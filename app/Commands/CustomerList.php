<?php

namespace App\Commands;

class CustomerList extends MrCommand
{
    protected $name        = 'mr:customer:list';
    protected $description = 'Lists customers.';
    protected $usage       = 'mr:customer:list [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $rows = service('customerService')->list();
        $this->json() ? $this->outputJson($rows) : $this->outputTable($rows, ['customer_key', 'name', 'enabled', 'managed_by', 'created_at']);

        return null;
    }
}
