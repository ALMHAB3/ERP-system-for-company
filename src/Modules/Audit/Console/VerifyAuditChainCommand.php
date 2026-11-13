<?php

declare(strict_types=1);

namespace Modules\Audit\Console;

use Illuminate\Console\Command;
use Modules\Audit\Application\AuditVerifier;

final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Recompute the audit hash chain and report the first tampered entry.';

    public function handle(AuditVerifier $verifier): int
    {
        $r = $verifier->verify();
        if ($r['ok']) {
            $this->info("Audit chain intact ({$r['checked']} entries).");

            return self::SUCCESS;
        }
        $this->error("Audit chain BROKEN at entry #{$r['broken_at']}: {$r['reason']}");

        return self::FAILURE;
    }
}
