<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

/** Who/where of the current request or job. Scoped per request; filled by middleware. */
final class AuditContext
{
    public ?int $userId = null;

    public ?string $ip = null;

    public ?string $userAgent = null;

    public ?string $requestId = null;
}
