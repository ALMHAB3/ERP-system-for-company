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
        // `permission:a|b` = any of
        $userId = (int) $request->user()->id;
        $allowed = array_filter(explode('|', $permission), fn (string $p) => $this->access->can($userId, Permission::from($p)));
        if ($allowed === []) {
            $this->access->authorize($userId, Permission::from(explode('|', $permission)[0]));   // raises the 403
        }

        return $next($request);
    }
}
