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
