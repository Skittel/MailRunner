<?php

namespace App\Commands;

/**
 * mr:customer:set - changes name or enabled state of a customer (same as the admin app; a disabled customer
 * accepts no mail in any of its projects).
 */
class CustomerSet extends MrCommand
{
    protected $name        = 'mr:customer:set';
    protected $description = 'Changes a customer (name, enabled). Disabled: no project of the customer accepts mail.';
    protected $usage       = 'mr:customer:set <customer> [--name="Display name"] [--enabled=1|0] [--force] [--json]';
    protected $arguments   = ['customer' => 'Customer key'];
    protected $options     = ['--name' => 'Display name', '--enabled' => '1 or 0', '--force' => 'Change a customer of the admin app', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $changes = [];
        if (($name = $this->optionString('name')) !== null) {
            $changes['name'] = $name;
        }
        if (($enabled = $this->optionString('enabled')) !== null) {
            $changes['enabled'] = filter_var($enabled, FILTER_VALIDATE_BOOL);
        }
        if ($changes === []) {
            throw new \InvalidArgumentException('Nothing to change: use --name and/or --enabled');
        }
        $customer = service('customerService')->update($this->requireArg(0, 'customer'), $changes, $this->context());
        $this->json() ? $this->outputJson($customer) : $this->ok('Customer "' . $customer['customer_key'] . '" saved.');

        return null;
    }
}
