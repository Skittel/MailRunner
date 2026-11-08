<?php

use CodeIgniter\Router\RouteCollection;

/**
 * MailRunner Admin. Everything except /auth/* requires a signed-in administrator (filter adminauth).
 *
 * @var RouteCollection $routes
 */
$routes->addPlaceholder('pid', \App\Libraries\PublicId::PATTERN);

$routes->get('auth/login', 'Auth::login');
$routes->get('auth/callback', 'Auth::callback');
$routes->get('auth/logout', 'Auth::logout');

// node self-registration (install.sh --enroll-token): no session, one-time token
$routes->get('enroll/ca.pem', 'Enroll::ca');
$routes->get('enroll/config', 'Enroll::config');
$routes->get('enroll/bootstrap.sh', 'Enroll::bootstrap');
$routes->post('enroll', 'Enroll::register');

$routes->group('', ['filter' => 'adminauth'], static function (RouteCollection $routes): void {
    $routes->get('/', 'Nodes::dashboard');
    $routes->get('nodes', 'Nodes::index');
    $routes->get('nodes/cards', 'Nodes::cards');
    $routes->get('nodes/new', 'Nodes::new');
    $routes->post('nodes', 'Nodes::create');
    $routes->get('nodes/enroll', 'Nodes::enrollForm');
    $routes->post('nodes/enroll', 'Nodes::enroll');
    $routes->get('nodes/enroll/dns', 'Nodes::enrollDns');
    $routes->get('nodes/(:pid)', 'Nodes::show/$1');
    $routes->post('nodes/(:pid)/poll', 'Nodes::poll/$1');
    $routes->get('nodes/(:pid)/journal', 'Nodes::journal/$1');
    $routes->post('nodes/(:pid)/import', 'Nodes::import/$1');

    $routes->get('customers', 'Customers::index');
    $routes->get('customers/new', 'Customers::new');
    $routes->post('customers', 'Customers::create');
    $routes->get('customers/(:pid)', 'Customers::show/$1');
    $routes->post('customers/(:pid)', 'Customers::update/$1');
    $routes->get('customers/(:pid)/projects/new', 'Projects::new/$1');
    $routes->post('customers/(:pid)/projects', 'Projects::create/$1');

    $routes->get('projects/(:pid)', 'Projects::show/$1');
    $routes->post('projects/(:pid)', 'Projects::update/$1');
    $routes->post('projects/(:pid)/push', 'Projects::push/$1');
    $routes->post('projects/(:pid)/move', 'Projects::move/$1');
    $routes->post('projects/(:pid)/move/finish', 'Projects::moveFinish/$1');
    $routes->post('projects/(:pid)/move/cancel', 'Projects::moveCancel/$1');
    $routes->get('projects/(:pid)/send', 'Projects::send/$1');
    $routes->post('projects/(:pid)/send/sync', 'Projects::sendSync/$1');
    $routes->get('projects/(:pid)/tokens', 'Projects::tokens/$1');
    $routes->post('projects/(:pid)/tokens', 'Projects::createToken/$1');
    $routes->post('projects/(:pid)/tokens/(:segment)/revoke', 'Projects::revokeToken/$1/$2');

    $routes->get('role-mails', 'RoleMails::index');
    $routes->get('role-mails/node/(:pid)', 'RoleMails::list/$1');
    $routes->get('role-mails/(:pid)/(:segment)/raw', 'RoleMails::raw/$1/$2');
    $routes->get('role-mails/(:pid)/(:segment)', 'RoleMails::show/$1/$2');

    $routes->get('audit', 'AuditLog::index');

    $routes->get('settings', 'Settings::index');
    $routes->post('settings/secrets', 'Settings::saveSecrets');
});
