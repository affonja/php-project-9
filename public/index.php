<?php

use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\Container;
use App\Database;

require __DIR__ . '/../vendor/autoload.php';
session_start();

$pdo = new Database();
$pdo->createTables();

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

function validateUrl(): array
{
    $v = new Valitron\Validator($_POST);
    $v->rule('required', 'url.name')->message('URL не должен быть пустым');
    $v->rule('max', '255')->message('URL не должен превышать 255 символов');
    $v->rule('url', 'url.name')->message('Некорректный URL');
    return ['result' => $v->validate(), 'error' => $v->errors()['url.name'][0]];
}

$app->get('/', function ($request, $response) {
    $messages = $this->get('flash')->getMessages();
    $params = [
        'flash' => $messages
    ];
    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('main');

$app->get('/urls', function ($request, $response) use ($pdo) {
    $sql = "SELECT u.id, u.name, sq.checks, uc.status_code FROM urls u
    inner join
        (SELECT url_id, MAX(created_at) AS checks
        FROM url_checks
        GROUP BY url_id) as sq on u.id = sq.url_id
    inner join url_checks uc on u.id = uc.url_id
    group by u.id , u.name,sq.checks, uc.status_code
    ORDER BY u.id DESC;";
    $sites = $pdo->getAll($sql);
    $params = ['sites' => $sites];
    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName('urls');

$app->post('/urls', function ($request, $response) use ($pdo) {
    $isValid = validateUrl();
    if (!$isValid['result']) {
        $message = $isValid['error'];
        $this->get('flash')->addMessage('error', $message);
        return $response->withRedirect('/', 302);
    }

    $siteUrl = $request->getParam('url')['name'];
    $normalizedUrl = parse_url($siteUrl, 0) . '://' . parse_url($siteUrl, 1);
    $sql = "select id from urls where name='$normalizedUrl'";
    $isExist = !(($pdo->getAll($sql) === null));
    if ($isExist) {
        $id = $pdo->getAll($sql)[0]['id'];
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withStatus(302)->withHeader('Location', "/urls/$id");
    }
    $date = Carbon::now()->toDateTimeString();
    $sql = "insert into urls(name, created_at) values ('$normalizedUrl', '$date');";
    $idNew = $pdo->query($sql);
    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    return $response->withStatus(302)->withHeader('Location', "/urls/$idNew");
})->setName('urlpost');

$app->get('/urls/{url_id}', function ($request, $response, $args) use ($pdo) {
    $messages = $this->get('flash')->getMessages();

    $sql = "select * from urls where id={$args['url_id']}";
    $query = $pdo->getAll($sql)[0];
    $sql2 = "select * from url_checks where url_id={$args['url_id']} order by id DESC ";
    $query2 = $pdo->getAll($sql2);
    $params = [
        'url_id' => $query['id'],
        'siteUrl' => $query['name'],
        'created' => $query['created_at'],
        'flash' => $messages,
        'checks' => $query2
    ];
    return $this->get('renderer')->render($response, 'url.phtml', $params);
})->setName('url');

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($pdo) {
    $url_id = $args['url_id'];
    $date = Carbon::now()->toDateTimeString();
    $sql = "insert into url_checks(url_id, status_code, h1, title, description, created_at)
values ('$url_id', 0, 'h1', 'title', 'description', '$date');";
    $idNew = $pdo->query($sql);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    return $response->withStatus(302)->withHeader('Location', "/urls/$url_id");
})->setName('urlpostcheck');

$app->run();
