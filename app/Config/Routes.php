<?php

use App\Core\Api\ApiResponse;
use CodeIgniter\Router\RouteCollection;

/**
 * API routes. Only the routes of enabled roles are registered (Config\Mailrunner).
 *
 * @var RouteCollection $routes
 */
$routes->set404Override(static fn () => ApiResponse::error(404, 'NOT_FOUND', 'Unknown endpoint')->send());

$routes->group('v1', ['filter' => 'bearer'], static function (RouteCollection $routes): void {
    $routes->get('node/status', '\App\Controllers\V1\NodeStatus::show');

    if (config('Mailrunner')->receiveEnabled) {
        $routes->get('journal', '\App\Modules\Receive\Controllers\Customer\Journal::index');
        $routes->get('messages', '\App\Modules\Receive\Controllers\Customer\Messages::index');
        $routes->post('messages/ack', '\App\Modules\Receive\Controllers\Customer\Messages::ack');
        $routes->get('messages/(:segment)', '\App\Modules\Receive\Controllers\Customer\Messages::show/$1');
    }
});
