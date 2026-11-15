<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Application\AccessControl;
use Modules\Identity\Domain\AccessDenied;
use Symfony\Component\HttpFoundation\Response;

/** Screens that show the whole company's books (suppliers, imports, banks, revaluation): never for branch-limited users. */
final class RequireCompanyWide
{
    public function __construct(private readonly AccessControl $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->access->branchScope((int) $request->user()->id) !== null) {
            throw new AccessDenied('This screen covers the whole company; it is not available to branch staff.');
        }

        return $next($request);
    }
}
