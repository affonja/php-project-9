<?php

use Slim\Factory\AppFactory;
use DI\Container;
use App\Database;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;

require __DIR__ . '/../vendor/autoload.php';
session_start();

try {
    $db = new Database();
} catch (Exception $e) {
    die('Ошибка подключения к базе данных: ' . $e->getMessage());
}

try {
    $container = new Container();
    $container->set('renderer', function () {
        return new PhpRenderer(__DIR__ . '/../templates');
    });
    $container->set('flash', function () {
        return new Messages();
    });
    AppFactory::setContainer($container);
    $app = AppFactory::createFromContainer($container);
} catch (Exception $e) {
    die('Ошибка создания объекта AppFactory: ' . $e->getMessage());
}

$app->addErrorMiddleware(true, true, true);
$router = $app->getRouteCollector()->getRouteParser();

require __DIR__ . '/../app/routes.php';

$app->run();

function validateUrl(): array
{
    $v = new Valitron\Validator($_POST);
    $v->rule('required', 'url.name')->message('URL не должен быть пустым');
    $v->rule('max', '255')->message('URL не должен превышать 255 символов');
    $v->rule('url', 'url.name')->message('Некорректный URL');
    return ['result' => $v->validate(), 'error' => $v->errors()['url.name'][0] ?? []];
}
