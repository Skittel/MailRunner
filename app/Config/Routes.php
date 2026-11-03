<?php

use App\Core\Api\ApiResponse;
use CodeIgniter\Router\RouteCollection;

/**
 * API routes. Only the routes of enabled roles are registered (Config\Mailrunner).
 *
 * @var RouteCollection $routes
 */
// the closure's echoed string becomes the body; error() sets status 404 and headers on the shared response
$routes->set404Override(static fn (): string => ApiResponse::error(404, 'NOT_FOUND', 'Unknown endpoint')->getBody());

$routes->group('v1', ['filter' => 'bearer'], static function (RouteCollection $routes): void {
    $routes->get('node/status', '\App\Controllers\V1\NodeStatus::show');

    if (config('Mailrunner')->receiveEnabled) {
        $routes->get('journal', '\App\Modules\Receive\Controllers\Customer\Journal::index');
        $routes->get('messages', '\App\Modules\Receive\Controllers\Customer\Messages::index');
        $routes->post('messages/ack', '\App\Modules\Receive\Controllers\Customer\Messages::ack');
        $routes->get('messages/(:segment)/raw', '\App\Modules\Receive\Controllers\Customer\Messages::raw/$1');
        $routes->get('messages/(:segment)', '\App\Modules\Receive\Controllers\Customer\Messages::show/$1');
    }
});

// Node admin API (decision E-19): mTLS with the MailRunner Admin CA, used by the admin GUI.
$routes->group('admin/v1', ['filter' => 'admincert'], static function (RouteCollection $routes): void {
    $routes->get('node', '\App\Controllers\Admin\V1\Node::show');
    $routes->put('node/update', '\App\Controllers\Admin\V1\Node::requestUpdate');
    $routes->get('journal', '\App\Controllers\Admin\V1\Node::journal');
    $routes->get('customers', '\App\Controllers\Admin\V1\Customers::index');
    $routes->put('customers/(:segment)', '\App\Controllers\Admin\V1\Customers::put/$1');
    $routes->get('projects', '\App\Controllers\Admin\V1\Projects::index');
    $routes->get('projects/(:segment)/(:segment)', '\App\Controllers\Admin\V1\Projects::show/$1/$2');
    $routes->put('projects/(:segment)/(:segment)', '\App\Controllers\Admin\V1\Projects::put/$1/$2');
    $routes->post('projects/(:segment)/(:segment)/tokens', '\App\Controllers\Admin\V1\Projects::createToken/$1/$2');
    $routes->delete('tokens/(:segment)', '\App\Controllers\Admin\V1\Projects::revokeToken/$1');

    if (config('Mailrunner')->receiveEnabled) {
        $routes->get('role-mails', '\App\Controllers\Admin\V1\RoleMails::index');
        $routes->get('messages/(:segment)/raw', '\App\Controllers\Admin\V1\RoleMails::raw/$1');
        $routes->get('messages/(:segment)', '\App\Controllers\Admin\V1\RoleMails::show/$1');
    }
});
