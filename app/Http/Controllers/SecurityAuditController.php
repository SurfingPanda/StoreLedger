<?php
namespace App\Http\Controllers;

use App\Services\TableSort;

use App\Models\SecurityAuditLog;
use App\Services\SecurityAudit;
use App\Services\RequestModule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SecurityAuditController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['category' => ['nullable', Rule::in(['all', ...SecurityAudit::CATEGORIES])],
            'outcome' => ['nullable', 'in:all,success,failure,denied'], 'event' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:150'], 'page' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')]]);
        $query = SecurityAuditLog::query();
        if (($data['category'] ?? 'all') !== 'all') $query->where('category', $data['category']);
        if (($data['outcome'] ?? 'all') !== 'all') $query->where('outcome', $data['outcome']);
        if (!empty($data['event'])) $query->where('event', $data['event']);
        if (!empty($data['search'])) {
            $search = '%'.addcslashes($data['search'], '%_\\').'%';
            $query->where(fn ($query) => $query->where('actor_name', 'like', $search)->orWhere('actor_email', 'like', $search)
                ->orWhere('ip_address', 'like', $search)->orWhere('subject_id', 'like', $search)->orWhere('request_id', 'like', $search)
                ->orWhere('metadata->attempted_email', 'like', $search));
        }
        if (!empty($data['date_from'])) $query->where('occurred_at', '>=', CarbonImmutable::parse($data['date_from'], 'Asia/Manila')->startOfDay()->setTimezone(config('app.timezone')));
        if (!empty($data['date_to'])) $query->where('occurred_at', '<=', CarbonImmutable::parse($data['date_to'], 'Asia/Manila')->endOfDay()->setTimezone(config('app.timezone')));
        TableSort::apply($query, $request, ['occurred_at' => 'occurred_at', 'actor_name' => 'actor_name', 'event' => 'event', 'outcome' => 'outcome', 'target' => 'subject_type', 'ip_address' => 'ip_address'], 'occurred_at', 'desc');
        $records = $query->paginate($this->pageSize($request, 20))->through(function ($log) {
            $row = $log->toArray();
            $row['detail_url'] = $log->record_id && array_key_exists($log->module ?? '', RequestModule::LABELS) ? route($log->module.'.detail', $log->record_id) : null;
            return $row;
        });
        $since = now()->subDay();
        return response()->json(['records' => $records, 'events' => SecurityAuditLog::distinct()->orderBy('event')->pluck('event'),
            'summary' => ['total' => SecurityAuditLog::count(),
                'failed_signins_24h' => SecurityAuditLog::where('event', 'auth.sign_in_failed')->where('occurred_at', '>=', $since)->count(),
                'denied_24h' => SecurityAuditLog::where('outcome', 'denied')->where('occurred_at', '>=', $since)->count(),
                'sensitive_changes_24h' => SecurityAuditLog::whereIn('category', ['accounts', 'notifications', 'billing'])->where('outcome', 'success')->where('occurred_at', '>=', $since)->count()],
            'updated_at' => now()->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }
}
