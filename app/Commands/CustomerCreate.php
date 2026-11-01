<?php

namespace App\Commands;

class CustomerCreate extends MrCommand
{
    protected $name        = 'mr:customer:create';
    protected $description = 'Creates a customer.';
    protected $usage       = 'mr:customer:create <customer> [--name="Display name"] [--json]';
    protected $arguments   = ['customer' => 'Customer key (a-z, 0-9, -)'];
    protected $options     = ['--name' => 'Display name', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $customer = service('customerService')->create(
            $this->requireArg(0, 'customer'),
            $this->optionString('name') ?? '',
            $this->context(),
        );
        $this->json() ? $this->outputJson($customer) : $this->ok('Customer "' . $customer['customer_key'] . '" created.');

        return null;
    }
}
