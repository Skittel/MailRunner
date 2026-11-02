<?php

namespace App\Controllers\Admin\V1;

use App\Core\Admin\AdminChanges;
use App\Core\Api\ApiResponse;

/**
 * GET /admin/v1/customers
 * PUT /admin/v1/customers/{key}  {"name": "...", "enabled": true}  (creates or updates, idempotent)
 */
class Customers extends AdminController
{
    public function index()
    {
        return ApiResponse::ok(['data' => array_map([self::class, 'present'], service('customerService')->list())]);
    }

    public function put(string $key)
    {
        return $this->guard(function () use ($key) {
            $body = $this->body();
            if ($this->configManual()) {
                return $this->queued('customer', $key, $body);
            }
            [$customer, $created] = (new AdminChanges())->customer($key, $body, $this->ctx());

            return ApiResponse::ok(['data' => self::present($customer), 'created' => $created], $created ? 201 : 200);
        });
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return array<string, mixed>
     */
    public static function present(array $c): array
    {
        return ['key' => $c['customer_key'], 'name' => $c['name'], 'enabled' => (bool) $c['enabled'], 'managed_by' => $c['managed_by']];
    }
}
