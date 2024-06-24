<?php

use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use DI\Container;
use App\Database;
use Slim\Psr7\Response;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;

require __DIR__ . '/../vendor/autoload.php';
session_start();

$container = new Container();
$app = AppFactory::createFromContainer($container);
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

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
$container->set('renderer', function () use ($app, $container) {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout("layout.phtml");
    $router = $app->getRouteCollector()->getRouteParser();
    $renderer->addAttribute('router', $router);
    $messages = $container->get('flash')->getMessages();
    $renderer->addAttribute('flash', $messages);

    return $renderer;
});

require __DIR__ . '/../app/routes.php';

$app->run();
