<?php

namespace App\Controllers\Admin\V1;

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
            $body    = $this->body();
            $service = service('customerService');
            $created = false;
            if ($service->findByKey($key) === null) {
                $service->create($key, (string) ($body['name'] ?? ''), $this->ctx());
                $created = true;
            }
            $changes = array_intersect_key($body, ['name' => 1, 'enabled' => 1]);
            $customer = $changes === [] ? $service->getByKey($key) : $service->update($key, $changes, $this->ctx());

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
