<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

use Illuminate\Database\ConnectionInterface;

/** Recomputes the hash chain; any edited, deleted or forged row breaks it from that point on. */
final class AuditVerifier
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array{ok: bool, checked: int, broken_at: ?int, reason: ?string} */
    public function verify(): array
    {
        $previous = str_repeat('0', 64);
        $checked = 0;
        foreach ($this->db->table('audit_logs')->orderBy('id')->cursor() as $row) {
            $checked++;
            if ($row->prev_hash !== $previous) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $row->id, 'reason' => 'prev_hash does not link to the previous entry'];
            }
            if (! hash_equals(ChainedHash::of($row), $row->hash)) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $row->id, 'reason' => 'content does not match its hash'];
            }
            $previous = $row->hash;
        }
        $head = $this->db->table('audit_chain_head')->where('id', 1)->value('last_hash');
        if ($head !== null && $head !== $previous) {
            return ['ok' => false, 'checked' => $checked, 'broken_at' => null, 'reason' => 'chain head does not match the last entry (rows removed from the end)'];
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'reason' => null];
    }
}
