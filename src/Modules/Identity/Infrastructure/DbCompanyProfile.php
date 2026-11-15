<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Shared\Domain\CompanyProfile;

/** The administrator's on-screen company details; a field left empty there falls back to the .env setting. */
final class DbCompanyProfile implements CompanyProfile
{
    public const FIELDS = ['name', 'tax_id', 'commercial_register', 'address', 'phone', 'email'];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function get(): array
    {
        $row = (array) ($this->db->table('company_profile')->where('id', 1)->first() ?? []);
        $env = (array) config('erp.company') + ['name' => config('erp.company_name')];
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            $out[$field] = $value !== '' ? $value : trim((string) ($env[$field] ?? ''));
        }

        return $out;
    }

    public function name(): string
    {
        return $this->get()['name'];
    }

    /** @param array<string,?string> $values */
    public function save(array $values, ?int $userId): void
    {
        $data = array_intersect_key($values, array_flip(self::FIELDS));
        $this->db->table('company_profile')->updateOrInsert(['id' => 1], $data + ['updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()]);
    }
}
