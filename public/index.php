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
    $messages = $this->get('flash')->getMessages();
    $params = [
        'flash' => $messages
    ];
    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('main');

$app->get('/urls', function ($request, $response) use ($pdo) {
    $sql = "select * from urls order by created_at desc";
    $sites = $pdo->query($sql);
    $params = ['sites' => $sites];
    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName('urls');

$app->post('/urls', function ($request, $response) use ($pdo) {
    $v = new Valitron\Validator($_POST);

    $v->rule('required', 'url.name')->message('URL не должен быть пустым');
    $v->rule('max', '255')->message('URL не должен превышать 255 символов');
    $v->rule('url', 'url.name')->message('Некорректный URL');

    if (!($v->validate())) {
        $message = $v->errors()['url.name'][0];
        $this->get('flash')->addMessage('error', $message);

        return $response->withRedirect('/', 302);
//        return $response->withStatus(302)->withHeader('Location', "/");
    }


    $siteUrl = $request->getParam('url')['name'];
    $parseUrl = parse_url($siteUrl, 0) . '://' . parse_url($siteUrl, 1);
    $sql = "select id from urls where name='$parseUrl'";
    $isExist = !(($pdo->query($sql) === null));
    if ($isExist) {
        $id = $pdo->query($sql)[0]['id'];
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withStatus(302)->withHeader('Location', "/urls/$id");
    }
    $idNew = $pdo->insert($parseUrl);
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
