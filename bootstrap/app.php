<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Audit\Http\Middleware\CaptureAuditContext;
use Modules\Identity\Domain\AccessDenied;
use Modules\Identity\Http\Middleware\RequireCompanyWide;
use Modules\Identity\Http\Middleware\RequirePermission;
use Modules\Inventory\Domain\Exceptions\InsufficientStock;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Caddy (docker): take the client address from X-Forwarded-For so rate limits and the audit log see the real device.
        if (env('TRUST_PROXIES')) {
            $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR);
        }
        $middleware->api(prepend: [CaptureAuditContext::class]);
        $middleware->alias(['permission' => RequirePermission::class, 'company-wide' => RequireCompanyWide::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (AccessDenied $e) => response()->json(['message' => $e->getMessage()], 403));
        $exceptions->render(fn (InsufficientStock $e) => response()->json(['message' => $e->getMessage(), 'code' => 'insufficient_stock'], 409));
        // Business-rule violations raised by the ERP modules are the client's problem (422), not a server error.
        $exceptions->render(function (DomainException|InvalidArgumentException $e) {
            if (! str_starts_with($e->getFile(), dirname(__DIR__).'/src/Modules/')) {
                return null;
            }

            return response()->json(['message' => $e->getMessage(), 'code' => class_basename($e)], 422);
        });
        $exceptions->dontReport([AccessDenied::class, InsufficientStock::class, DomainException::class]);
    })->create();
