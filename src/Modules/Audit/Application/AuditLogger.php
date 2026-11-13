<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

interface AuditLogger
{
    /**
     * Appends a tamper-evident entry. Runs inside the caller's transaction when there is one,
     * so a rolled-back operation leaves no audit entry claiming it happened.
     *
     * @param  array<string,mixed>  $data
     */
    public function record(string $action, ?string $subjectType = null, int|string|null $subjectId = null, array $data = [], ?int $userId = null): void;
}
