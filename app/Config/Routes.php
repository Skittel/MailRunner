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

    // webhook ping: new mail (receive projects, event "messages") or status changes (send projects, event "delivery")
    $routes->get('webhook', '\App\Controllers\V1\Webhook::show');
    $routes->put('webhook', '\App\Controllers\V1\Webhook::put');
    $routes->delete('webhook', '\App\Controllers\V1\Webhook::delete');

    if (config('Mailrunner')->receiveEnabled) {
        $routes->get('journal', '\App\Modules\Receive\Controllers\Customer\Journal::index');
        $routes->get('messages', '\App\Modules\Receive\Controllers\Customer\Messages::index');
        $routes->post('messages/ack', '\App\Modules\Receive\Controllers\Customer\Messages::ack');
        $routes->get('messages/(:segment)/raw', '\App\Modules\Receive\Controllers\Customer\Messages::raw/$1');
        $routes->get('messages/(:segment)', '\App\Modules\Receive\Controllers\Customer\Messages::show/$1');
        $routes->get('smtp-credentials', '\App\Controllers\V1\SmtpCredentials::index');
        $routes->post('smtp-credentials', '\App\Controllers\V1\SmtpCredentials::create');
        $routes->patch('smtp-credentials/(:segment)', '\App\Controllers\V1\SmtpCredentials::update/$1');
        $routes->post('smtp-credentials/(:segment)/rotate', '\App\Controllers\V1\SmtpCredentials::rotate/$1');
        $routes->delete('smtp-credentials/(:segment)', '\App\Controllers\V1\SmtpCredentials::delete/$1');
    }
    if (config('Mailrunner')->sendEnabled) { // send projects (E-27)
        $routes->get('domains', '\App\Controllers\V1\SendDomains::index');
        $routes->get('domains/(:segment)', '\App\Controllers\V1\SendDomains::show/$1');
        $routes->put('domains/(:segment)', '\App\Controllers\V1\SendDomains::put/$1');
        $routes->delete('domains/(:segment)', '\App\Controllers\V1\SendDomains::delete/$1');
        $routes->post('domains/(:segment)/test', '\App\Controllers\V1\SendDomains::test/$1');
        $routes->get('domains/(:segment)/tests', '\App\Controllers\V1\SendDomains::tests/$1');
        $routes->post('dkim/generate', '\App\Controllers\V1\SendDomains::generate');
        $routes->post('send', '\App\Controllers\V1\Send::submit');
        $routes->get('send/submissions/(:segment)', '\App\Controllers\V1\Send::submission/$1');
        $routes->get('send/messages', '\App\Controllers\V1\Send::messages');
        $routes->get('send/messages/(:segment)', '\App\Controllers\V1\Send::message/$1');
        $routes->get('send-profiles', '\App\Controllers\V1\SendProfiles::index');
        $routes->get('send-profiles/(:segment)', '\App\Controllers\V1\SendProfiles::show/$1');
        $routes->put('send-profiles/(:segment)', '\App\Controllers\V1\SendProfiles::put/$1');
        $routes->delete('send-profiles/(:segment)', '\App\Controllers\V1\SendProfiles::delete/$1');
        $routes->get('send-profiles/(:segment)/accesses', '\App\Controllers\V1\SendProfiles::accesses/$1');
        $routes->post('send-profiles/(:segment)/accesses', '\App\Controllers\V1\SendProfiles::createAccess/$1');
        $routes->patch('send-accesses/(:segment)', '\App\Controllers\V1\SendProfiles::updateAccess/$1');
        $routes->post('send-accesses/(:segment)/rotate', '\App\Controllers\V1\SendProfiles::rotateAccess/$1');
        $routes->delete('send-accesses/(:segment)', '\App\Controllers\V1\SendProfiles::deleteAccess/$1');
    }
});

// Node admin API (decision E-19): mTLS with the MailRunner Admin CA, used by the admin GUI.
$routes->group('admin/v1', ['filter' => 'admincert'], static function (RouteCollection $routes): void {
    $routes->get('node', '\App\Controllers\Admin\V1\Node::show');
    $routes->put('node/update', '\App\Controllers\Admin\V1\Node::requestUpdate');
    $routes->put('node/secrets', '\App\Controllers\Admin\V1\Node::putSecrets');
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
