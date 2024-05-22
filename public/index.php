<?php

use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\Container;
use App\Database;
use GuzzleHttp\Client;
use DiDom\Document;
use DiDom\Query;

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
    $sql = "SELECT u.id as id, u.name as name, uc.status_code,v.last_check as last_check
            from url_checks uc
                inner join (select url_id, max(created_at) as last_check
                     from url_checks group by url_id) as v
                    on v.url_id = uc.url_id and v.last_check = uc.created_at
                right join urls u on u.id = v.url_id
            order by u.id desc;";

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
    $url_name = $pdo->getAll("select name from urls where id=$url_id")[0]['name'];

    $client = new GuzzleHttp\Client();
    try {
        $response = $client->get($url_name);
    } catch (GuzzleHttp\Exception\GuzzleException $e) {
        $this->get('flash')->addMessage('error', 'Ошибка при обращении к сайту');
        return $response->withStatus(302)->withHeader('Location', "/urls/$url_id");
    }
    $statusCode = $response->getStatusCode();
    $date = Carbon::now()->toDateTimeString();

    $document = new Document($url_name, true);
    $h1 = ($document->has('h1')) ? $document->find('h1')[0]->text() : '';
    $title = ($document->has('title')) ? $document->find('title')[0]->text() : '';
    $content = ($document->has('meta[name="description"]')) ?
        $document->find('meta[name="description"]')[0]->attr('content') : '';

    $sql = "insert into url_checks(url_id, status_code, h1, title, description, created_at)
            values ($url_id, $statusCode, '$h1', '$title', '$content', '$date');";
    $idNew = $pdo->query($sql);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    return $response->withStatus(302)->withHeader('Location', "/urls/$url_id");
})->setName('urlpostcheck');

$app->run();
