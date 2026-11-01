<?php

use CodeIgniter\Router\RouteCollection;

/**
 * MailRunner Admin. Everything except /auth/* requires a signed-in administrator (filter adminauth).
 *
 * @var RouteCollection $routes
 */
$routes->get('auth/login', 'Auth::login');
$routes->get('auth/callback', 'Auth::callback');
$routes->get('auth/logout', 'Auth::logout');

$routes->group('', ['filter' => 'adminauth'], static function (RouteCollection $routes): void {
    $routes->get('/', 'Nodes::dashboard');
    $routes->get('nodes', 'Nodes::index');
    $routes->get('nodes/cards', 'Nodes::cards');
    $routes->get('nodes/new', 'Nodes::new');
    $routes->post('nodes', 'Nodes::create');
    $routes->get('nodes/(:num)', 'Nodes::show/$1');
    $routes->post('nodes/(:num)/poll', 'Nodes::poll/$1');
    $routes->get('nodes/(:num)/journal', 'Nodes::journal/$1');
    $routes->post('nodes/(:num)/import', 'Nodes::import/$1');

    $routes->get('customers', 'Customers::index');
    $routes->get('customers/new', 'Customers::new');
    $routes->post('customers', 'Customers::create');
    $routes->get('customers/(:num)', 'Customers::show/$1');
    $routes->post('customers/(:num)', 'Customers::update/$1');
    $routes->get('customers/(:num)/projects/new', 'Projects::new/$1');
    $routes->post('customers/(:num)/projects', 'Projects::create/$1');

    $routes->get('projects/(:num)', 'Projects::show/$1');
    $routes->post('projects/(:num)', 'Projects::update/$1');
    $routes->post('projects/(:num)/push', 'Projects::push/$1');
    $routes->get('projects/(:num)/tokens', 'Projects::tokens/$1');
    $routes->post('projects/(:num)/tokens', 'Projects::createToken/$1');
    $routes->post('projects/(:num)/tokens/(:segment)/revoke', 'Projects::revokeToken/$1/$2');

    $routes->get('role-mails', 'RoleMails::index');
    $routes->get('role-mails/node/(:num)', 'RoleMails::list/$1');
    $routes->get('role-mails/(:num)/(:segment)/raw', 'RoleMails::raw/$1/$2');
    $routes->get('role-mails/(:num)/(:segment)', 'RoleMails::show/$1/$2');

    $routes->get('audit', 'AuditLog::index');
});
