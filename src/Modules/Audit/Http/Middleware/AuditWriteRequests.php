<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Audit\Application\AuditContext;
use Modules\Audit\Application\AuditLogger;
use Symfony\Component\HttpFoundation\Response;

/** Logs every authenticated write request with its outcome, including refused (403) and failed attempts. */
final class AuditWriteRequests
{
    private const REDACT = ['password', 'password_confirmation', 'token', 'current_password'];

    public function __construct(private readonly AuditLogger $audit, private readonly AuditContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->userId = $request->user()?->id;
        $response = $next($request);

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->audit->record('http.'.strtolower($request->method()), 'route', $request->route()?->getName() ?? $request->path(), [
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'input' => $this->redact($request->all()),
            ]);
        }

        return $response;
    }

    private function redact(array $input): array
    {
        foreach ($input as $key => $value) {
            if (in_array($key, self::REDACT, true)) {
                $input[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $input[$key] = $this->redact($value);
            }
        }

        return $input;
    }
}
