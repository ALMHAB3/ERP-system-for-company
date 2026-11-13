<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Application\AccessControl;
use Modules\Identity\Domain\Permission;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware: `permission:sales.create`. */
final class RequirePermission
{
    public function __construct(private readonly AccessControl $access) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $this->access->authorize((int) $request->user()->id, Permission::from($permission));

        return $next($request);
    }
}
