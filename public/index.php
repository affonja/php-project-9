<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\Container;
use App\Database;

require __DIR__ . '/../vendor/autoload.php';
session_start();

$pdo = new Database();
$urls = $pdo->createTable();


$app = AppFactory::create();
$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
AppFactory::setContainer($container);

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);
$router = $app->getRouteCollector()->getRouteParser();


$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'main.phtml');
})->setName('main');

$app->get('/urls', function ($request, $response) use ($pdo) {
    $sql = "select * from urls";
    $sites = $pdo->query($sql);
    $params = ['sites' => $sites];
    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName('urls');

$app->post('/urls', function ($request, $response) use ($pdo) {
    $siteUrl = $request->getParam('url');
    $sql = "select id from urls where name='$siteUrl'";
    $isExist = !(($pdo->query($sql) === null));
    if ($isExist) {
        $id = $pdo->query($sql)[0]['id'];
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withStatus(302)->withHeader('Location', "/urls/$id");
    }
    $idNew = $pdo->insert($siteUrl);
    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    return $response->withStatus(302)->withHeader('Location', "/urls/$idNew");
})->setName('urlpost');

$app->get('/urls/{id}', function ($request, $response, $args) use ($pdo) {
    $messages = $this->get('flash')->getMessages();

    $sql = "select * from urls where id={$args['id']}";
    $query = $pdo->query($sql)[0];
    $params = [
        'id' => $query['id'],
        'siteUrl' => $query['name'],
        'created' => $query['created_at'],
        'flash' => $messages
    ];
    return $this->get('renderer')->render($response, 'url.phtml', $params);
})->setName('url');

$app->run();
