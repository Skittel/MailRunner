<?php

namespace App\Core\Projects;

use App\Core\Journal\JournalWriter;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;

class CustomerService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $key, string $name, ChangeContext $ctx): array
    {
        Keys::assertValid($key, 'customer');
        $name = trim($name) !== '' ? trim($name) : $key;

        return Transaction::run($this->db, function () use ($key, $name, $ctx): array {
            if ($this->findByKey($key) !== null) {
                throw new ConfigException('CUSTOMER_EXISTS', 'Customer "' . $key . '" already exists');
            }
            $now = Time::now();
            $this->db->table('customers')->insert([
                'customer_key' => $key,
                'name'         => $name,
                'enabled'      => 1,
                'managed_by'   => $ctx->source === 'admin' ? 'admin' : 'cli',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $id = (int) $this->db->insertID();
            $this->journal->append('customer.created', $ctx, $id, null, ['customer' => $key]);

            return $this->getById($id);
        });
    }

    /**
     * Changes name and/or enabled (admin API, CLI later). Disabling a customer disables its projects
     * on the mail path (unknown recipient), so the mail path configuration is applied again.
     *
     * @param array{name?: string, enabled?: bool} $changes
     *
     * @return array<string, mixed>
     */
    public function update(string $key, array $changes, ChangeContext $ctx): array
    {
        $unknown = array_diff(array_keys($changes), ['name', 'enabled']);
        if ($unknown !== []) {
            throw new ConfigException('INVALID_SETTING', 'Unknown setting(s): ' . implode(', ', $unknown));
        }

        return Transaction::run($this->db, function () use ($key, $changes, $ctx): array {
            $customer = $this->getByKey($key);
            $row      = [];
            if (array_key_exists('name', $changes)) {
                $row['name'] = trim((string) $changes['name']) !== '' ? trim((string) $changes['name']) : $key;
            }
            if (array_key_exists('enabled', $changes)) {
                $row['enabled'] = $changes['enabled'] ? 1 : 0;
            }
            $row = array_filter($row, static fn ($v, string $k): bool => (string) $v !== (string) ($customer[$k] ?? ''), ARRAY_FILTER_USE_BOTH);
            if ($row === []) {
                return $customer; // nothing changed: no journal entry
            }
            $changed = array_keys($row);
            if ($ctx->source === 'admin') {
                $row['managed_by'] = 'admin';
            }
            $this->db->table('customers')->where('id', $customer['id'])->update($row + ['updated_at' => Time::now()]);
            $this->journal->append('customer.updated', $ctx, (int) $customer['id'], null, ['customer' => $key, 'changed' => $changed]);
            if (array_key_exists('enabled', $row)) {
                ProjectService::requestApply();
            }

            return $this->getById((int) $customer['id']);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        return $this->db->table('customers')->where('customer_key', $key)->get()->getRowArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function getByKey(string $key): array
    {
        return $this->findByKey($key)
            ?? throw new ConfigException('CUSTOMER_NOT_FOUND', 'Customer "' . $key . '" not found');
    }

    /**
     * @return array<string, mixed>
     */
    public function getById(int $id): array
    {
        return $this->db->table('customers')->where('id', $id)->get()->getRowArray()
            ?? throw new ConfigException('CUSTOMER_NOT_FOUND', 'Customer #' . $id . ' not found');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->db->table('customers')->orderBy('customer_key')->get()->getResultArray();
    }
}
