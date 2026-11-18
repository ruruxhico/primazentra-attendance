<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Login (delegated to the main PrimaZentra app)
$routes->get('login', 'Auth::login');
$routes->post('login', 'Auth::processLogin');
$routes->post('logout', 'Auth::logout');

// Supervisor UI
$routes->group('', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/', 'Dtr::index');
    $routes->get('dtr', 'Dtr::index');
    $routes->get('dtr/day', 'Dtr::day');
    $routes->post('dtr/day', 'Dtr::saveDay');
    $routes->post('dtr/approve', 'Dtr::approve');
});

// Service API for hris-payroll (X-Api-Key, see the servicekey filter)
$routes->get('api/v1/dtr', 'Api\V1\Dtr::index');
$routes->post('api/v1/events', 'Api\V1\Events::receive');
