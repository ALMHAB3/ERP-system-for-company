<?php

declare(strict_types=1);

namespace Modules\Shared\Http;

use Illuminate\Http\Request;
use Modules\Identity\Application\AccessControl;
use Modules\Identity\Domain\Permission;

abstract class ApiController
{
    public function __construct(protected readonly AccessControl $access) {}

    protected function userId(Request $request): int
    {
        return (int) $request->user()->id;
    }

    protected function warehouse(Request $request, int ...$warehouseIds): void
    {
        $this->access->authorizeWarehouse($this->userId($request), ...$warehouseIds);
    }

    protected function can(Request $request, Permission $permission): bool
    {
        return $this->access->can($this->userId($request), $permission);
    }

    /**
     * Branches the signed-in user is limited to (null = the whole company).
     * A company-wide user may narrow a report with ?branch_id=; a branch user may only narrow inside their own branches.
     *
     * @return list<int>|null
     */
    protected function branchScope(Request $request): ?array
    {
        $scope = $this->access->branchScope($this->userId($request));
        $asked = $request->query('branch_id');
        if ($asked === null || $asked === '') {
            return $scope;
        }
        $asked = (int) $asked;
        if ($scope !== null && ! in_array($asked, $scope, true)) {
            $this->access->authorizeWarehouse($this->userId($request), $asked);   // raises the 403
        }

        return [$asked];
    }

    /** Optional client-supplied Idempotency-Key, namespaced per user so keys cannot collide across users. */
    protected function idempotencyKey(Request $request, string $scope): ?string
    {
        $key = (string) $request->header('Idempotency-Key', '');

        return preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key) ? "api:{$this->userId($request)}:{$scope}:{$key}" : null;
    }
}
