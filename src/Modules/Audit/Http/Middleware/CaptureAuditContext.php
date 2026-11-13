<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Audit\Application\AuditContext;
use Symfony\Component\HttpFoundation\Response;

final class CaptureAuditContext
{
    public function __construct(private readonly AuditContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $this->context->requestId = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();
        $this->context->ip = $request->ip();
        $this->context->userAgent = substr((string) $request->userAgent(), 0, 255);
        $this->context->userId = $request->user()?->id;

        $response = $next($request);
        $response->headers->set('X-Request-Id', $this->context->requestId);

        return $response;
    }
}
