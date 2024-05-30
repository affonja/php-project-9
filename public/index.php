<?php

use Slim\Factory\AppFactory;
use DI\Container;
use App\Database;
use Slim\Psr7\Response;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;

require __DIR__ . '/../vendor/autoload.php';
session_start();

$container = new Container();
$container->set('renderer', function () {
    return new PhpRenderer(__DIR__ . '/../templates');
});
$container->get('renderer')->setLayout("layout.php");
$container->set('flash', function () {
    return new Messages();
});
$container->set('db', function () {
    try {
        return new Database();
    } catch (Exception $e) {
        throw new Exception('Ошибка подключения к базе данных: ' . $e->getMessage());
    }
});
$container->get('db');

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

require __DIR__ . '/../app/routes.php';

$renderer = $container->get('renderer');
$routes = $app->getRouteCollector()->getRouteParser();
$renderer->addAttribute('routes', $routes);

$app->run();
