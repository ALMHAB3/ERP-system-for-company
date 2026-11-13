<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Audit\Application\AuditContext;
use Modules\Audit\Application\AuditLogger;
use Modules\Audit\Application\Canonical;
use Modules\Audit\Application\ChainedHash;

/**
 * Hash-chained, append-only audit trail. The single chain-head row is locked for the
 * duration of the append, which keeps the chain linear under concurrency.
 */
final class ChainedAuditLogger implements AuditLogger
{
    public function __construct(private readonly ConnectionInterface $db, private readonly AuditContext $context) {}

    public function record(string $action, ?string $subjectType = null, int|string|null $subjectId = null, array $data = [], ?int $userId = null): void
    {
        $this->db->transaction(function () use ($action, $subjectType, $subjectId, $data, $userId) {
            $head = $this->db->table('audit_chain_head')->where('id', 1)->lockForUpdate()->value('last_hash');

            $row = (object) [
                'prev_hash' => $head,
                'occurred_at' => now()->format('Y-m-d H:i:s'),
                'user_id' => $userId ?? $this->context->userId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId === null ? null : (string) $subjectId,
                'ip' => $this->context->ip,
                'request_id' => $this->context->requestId,
                'data' => Canonical::json($data === [] ? new \stdClass : $data),
            ];
            $row->hash = ChainedHash::of($row);

            $this->db->table('audit_logs')->insert((array) $row + ['user_agent' => $this->context->userAgent]);
            $this->db->table('audit_chain_head')->where('id', 1)->update(['last_hash' => $row->hash]);
        });
    }
}
