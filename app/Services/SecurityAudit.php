<?php
namespace App\Services;

use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Models\PosRenewal;
use App\Models\InternetSoa;
use App\Models\BillingSoa;
use App\Models\RequestRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SecurityAudit
{
    public const CATEGORIES = ['authentication', 'accounts', 'notifications', 'billing', 'directory', 'access', 'automation'];
    public const FIELDS = ['id', 'name', 'email', 'role', 'is_active', 'event', 'mode', 'emails', 'code', 'assigned_rso_id',
        'store_id', 'supplier_id', 'billing_account_id', 'module', 'store_name', 'provider', 'account_number', 'billing_cycle_months',
        'billing_period', 'renewal_date', 'due_date', 'amount', 'status', 'voucher_reference', 'voucher_action_by', 'voucher_action_at',
        'workflow_version', 'review_cycle', 'revision_target', 'returned_by', 'returned_at', 'duplicate_cancelled_at',
        'paid_on', 'method', 'reference', 'user_id', 'pos_renewal_id', 'internet_soa_id', 'billing_soa_id',
        'active', 'interval_months', 'lead_days', 'next_due_date', 'source_id', 'phone'];
    private const METADATA = ['attempted_email', 'http_status', 'changed_fields', 'password_changed', 'sessions_revoked', 'revision_id', 'side', 'proof_uploaded', 'source', 'delivery_id'];

    public static function active(?Request $request = null): bool
    {
        return ($request ?? request())->attributes->get('security_audit_active', false);
    }
    public static function console(callable $work): mixed
    {
        if (self::active()) return $work();
        $previous = request();
        $context = Request::create('/', 'CONSOLE');
        $context->attributes->set('security_audit_active', true);
        $context->attributes->set('security_audit_source', 'console');
        $context->setUserResolver(fn () => null);
        app()->instance('request', $context);
        try { return $work(); } finally { app()->instance('request', $previous); }
    }
    public static function context(Request $request): string
    {
        if (!$request->attributes->has('security_audit_request_id')) $request->attributes->set('security_audit_request_id', (string) Str::uuid());
        return $request->attributes->get('security_audit_request_id');
    }
    private static function text(?string $value, int $limit): ?string
    {
        return $value === null ? null : mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '', 0, $limit);
    }
    private static function value(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) return $value->format(DATE_ATOM);
        if (is_array($value)) return array_map(fn ($item) => is_scalar($item) ? self::value($item) : null, array_slice($value, 0, 30));
        return is_string($value) ? self::text($value, 500) : (is_scalar($value) || $value === null ? $value : null);
    }
    public static function clean(array $data, array $keys = self::FIELDS): array
    {
        return collect($data)->only($keys)->map(fn ($value) => self::value($value))->all();
    }
    public static function record(string $category, string $event, ?Model $subject = null, array $before = [], array $after = [], array $metadata = [], string $outcome = 'success', ?User $actor = null, bool $anonymous = false): void
    {
        $request = request();
        if (!self::active($request)) return;
        $metadata['source'] = $request->attributes->get('security_audit_source', 'web');
        $actor = $anonymous ? null : ($actor ?? $request->user());
        $module = null; $recordId = null;
        if ($subject instanceof PosRenewal || $subject instanceof InternetSoa || $subject instanceof BillingSoa) {
            $module = RequestModule::key($subject); $recordId = $subject->id;
        } elseif ($subject instanceof RequestRevision) { $module = $subject->module; $recordId = $subject->request_id; }
        elseif ($subject && in_array(class_basename($subject), ['PosRenewalPayment', 'InternetSoaPayment', 'BillingSoaPayment'], true)) {
            $module = match (class_basename($subject)) { 'PosRenewalPayment' => 'utak-pos', 'InternetSoaPayment' => 'internet-soa', default => \App\Models\BillingSoa::find($subject->billing_soa_id)?->module };
            $recordId = $subject->pos_renewal_id ?? $subject->internet_soa_id ?? $subject->billing_soa_id;
        }
        SecurityAuditLog::create(['request_id' => self::context($request),
            'actor_id' => $actor?->id, 'actor_name' => self::text($actor?->name, 100), 'actor_email' => self::text($actor?->email, 254), 'actor_role' => $actor?->role,
            'category' => $category, 'event' => $event, 'outcome' => $outcome,
            'subject_type' => $subject ? class_basename($subject) : null, 'subject_id' => $subject ? (string) $subject->getKey() : null,
            'module' => $module, 'record_id' => $recordId, 'ip_address' => $metadata['source'] === 'console' ? null : self::text($request->ip(), 45), 'user_agent' => self::text($request->userAgent(), 500),
            'method' => $request->method(), 'route' => self::text($request->route()?->uri(), 255),
            'before' => self::clean($before), 'after' => self::clean($after), 'metadata' => self::clean($metadata, self::METADATA), 'occurred_at' => now()]);
        $request->attributes->set('security_audit_recorded', true);
    }
}
