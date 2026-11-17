<?php
namespace App\Http\Middleware;

use App\Services\SecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CaptureSecurityActivity
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('security_audit_active', true);
        SecurityAudit::context($request);
        try {
            $response = $next($request);
            if (in_array($response->getStatusCode(), [401, 403, 419, 429])) $this->denied($response->getStatusCode());
            if ($response instanceof StreamedResponse && $response->getStatusCode() === 200 && str_starts_with($response->headers->get('content-disposition', ''), 'attachment')) {
                $subject = collect($request->route()?->parameters() ?? [])->first(fn ($item) => $item instanceof \Illuminate\Database\Eloquent\Model);
                SecurityAudit::record('access', 'attachment.download_requested', $subject, metadata: ['side' => $request->route('side')]);
            }
            if ($request->route()?->getName() === 'security-audit' && $response->getStatusCode() === 200) SecurityAudit::record('access', 'audit.viewed');
            $response->headers->set('X-Request-ID', SecurityAudit::context($request));
            return $response;
        } catch (Throwable $error) {
            $status = $error instanceof AuthenticationException ? 401 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : null);
            if (in_array($status, [401, 403, 419, 429])) $this->denied($status);
            throw $error;
        } finally {
            $request->attributes->set('security_audit_active', false);
        }
    }
    private function denied(int $status): void
    {
        if ($status === 429 && request()->attributes->get('security_audit_recorded')) return;
        $subject = collect(request()->route()?->parameters() ?? [])->first(fn ($item) => $item instanceof \Illuminate\Database\Eloquent\Model);
        SecurityAudit::record('access', $status === 429 ? 'access.rate_limited' : 'access.denied', $subject, metadata: ['http_status' => $status], outcome: 'denied');
    }
}
