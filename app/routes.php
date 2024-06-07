<?php

/**
 * @var App $app
 * @var Database $db
 */

use App\Database;
use Carbon\Carbon;
use DiDom\Document;
use Slim\App;
use GuzzleHttp\Client as GuzzClient;
use GuzzleHttp\Exception\GuzzleException as GuzzExeption;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support;
use DiDom\Exceptions;

$app->get('/', function ($request, $response) {
    $messages = $this->get('flash')->getMessages();
    $params = [
        'flash' => $messages
    ];
    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('main');

$app->get('/urls', function ($request, $response) {
    $sqlChecks = <<<SQL
SELECT url_id AS id, status_code, MAX(created_at) AS last_check
FROM url_checks
GROUP BY url_id, status_code
ORDER BY url_id DESC;
SQL;
    $checks = $this->get('db')->getAll($sqlChecks, []);
    $sqlUrls = "SELECT id, name FROM urls ORDER BY id DESC;";
    $urls = $this->get('db')->getAll($sqlUrls, []);

    $urls_keyed = collect(Arr::keyBy($urls, 'id'));
    $checks_keyed = collect(Arr::keyBy($checks, 'id'));
    $merged = $urls_keyed->map(function ($item, $key) use ($checks_keyed) {
        return $checks_keyed->has($key) ? array_merge($item, $checks_keyed->get($key)) : $item;
    });
    $params = ['sites' => $merged->all()];

    return $this->get('renderer')->render($response, '/components/urls/index.phtml', $params);
})->setName('urls.index');

$app->post('/urls', function ($request, $response) use ($app) {
    $url = $request->getParam('url')['name'];
    $validationUrl = validateUrl(['url' => $url]);
    if (!$validationUrl['result']) {
        $message = $validationUrl['error'];
        return $this->get('renderer')->render(
            $response->withStatus(422),
            'main.phtml',
            ['error' => $message, 'url' => htmlspecialchars($url)]
        );
    }

    $normalizedUrl = mb_strtolower(parse_url($url, 0) . '://' . parse_url($url, 1));
    $sql = "SELECT id FROM urls WHERE name=:normalizedUrl";
    $isExist = !empty($this->get('db')->getAll($sql, ['normalizedUrl' => $normalizedUrl]));
    if ($isExist) {
        $id = $this->get('db')->getAll($sql, ['normalizedUrl' => $normalizedUrl])[0]['id'];
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        $target = $app->getRouteCollector()->getRouteParser()->urlFor('urls.show', ['url_id' => $id]);

        return $response->withStatus(302)->withHeader('Location', $target);
    }
    $date = Carbon::now()->toDateTimeString();
    $sql = "INSERT INTO urls(name, created_at) VALUES (:normalizedUrl, :date);";
    $id = $this->get('db')->insert($sql, ['normalizedUrl' => $normalizedUrl, 'date' => $date]);
    $target = $app->getRouteCollector()->getRouteParser()->urlFor('urls.show', ['url_id' => $id]);
    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');

    return $response->withStatus(302)->withHeader('Location', $target);
})->setName('urls.store');

$app->get('/urls/{url_id}', function ($request, $response, $args) {
    $messages = $this->get('flash')->getMessages();

    $getUrlSql = "SELECT * FROM urls WHERE id=:id";
    $urlData = $this->get('db')->getAll($getUrlSql, ['id' => $args['url_id']]);
    if (!$urlData) {
        return $response->withStatus(404)->write('Page not found');
    }
    $getChecksSql = "SELECT * FROM url_checks WHERE url_id=:id ORDER BY id DESC ";
    $checksData = $this->get('db')->getAll($getChecksSql, ['id' => $args['url_id']]);
    $params = [
        'url_id' => $urlData[0]['id'],
        'site_url' => $urlData[0]['name'],
        'created' => $urlData[0]['created_at'],
        'flash' => $messages,
        'checks' => $checksData
    ];
    return $this->get('renderer')->render($response, '/components/urls/show.phtml', $params);
})->setName('urls.show');

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($app) {
    $url_id = $args['url_id'];
    $target = $app->getRouteCollector()->getRouteParser()->urlFor('urls.show', ['url_id' => $url_id]);
    $url_name = $this->get('db')->getAll("SELECT name FROM urls WHERE id=:url_id", ['url_id' => $url_id]);
    if (!$url_name) {
        $this->get('flash')->addMessage('error', 'Ошибка запроса к бд');
        return $response->withStatus(302)->withHeader('Location', $target);
    }
    $date = Carbon::now()->toDateTimeString();

    $sql = "INSERT INTO url_checks(url_id, status_code, h1, title, description, created_at)
            VALUES (:url_id, :statusCode, :h1, :title, :content, :date);";

    $client = new GuzzClient();
    try {
        $response = $client->get($url_name[0]['name']);
    } catch (GuzzExeption $e) {
        $this->get('flash')->addMessage('error', 'Ошибка при обращении к сайту');
        $statusCode = $e->getCode();
        $this->get('db')->insert($sql, [
            'url_id' => $url_id,
            'statusCode' => $statusCode,
            'h1' => null,
            'title' => null,
            'content' => null,
            'date' => $date
        ]);
        return $response->withStatus(302)->withHeader('Location', $target);
    }

    $statusCode = $response->getStatusCode();
    try {
        $document = new Document($url_name[0]['name'], true);
        $h1 = optional($document->first('h1'))->text();
        $title = optional($document->first('title'))->text();
        $content = optional($document->first('meta[name="description"]'))->attr('content');
        $this->get('db')->insert($sql, [
            'url_id' => $url_id,
            'statusCode' => $statusCode,
            'h1' => (isset($h1)) ? Support\Str::limit($h1, 255) : null,
            'title' => (isset($title)) ? Support\Str::limit($title, 255) : null,
            'content' => (isset($content)) ? Support\Str::limit($content, 1000) : null,
            'date' => $date
        ]);
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
        return $response->withStatus(302)->withHeader('Location', $target);
    } catch (Exception $e) {
        $this->get('db')->insert($sql, [
            'url_id' => $url_id,
            'statusCode' => $statusCode,
            'h1' => 'Ошибка при обращении к сайту',
            'title' => 'Ошибка при обращении к сайту',
            'content' => null,
            'date' => $date
        ]);
        $this->get('flash')->addMessage('error', 'Ошибка при обращении к сайту: ' . $e->getMessage());
        return $response->withStatus(302)->withHeader('Location', $target);
    }
})->setName('urls.checks.store');

function validateUrl(array $url): array
{
    $v = new Valitron\Validator($url);
    $v->rule('required', 'url')->message('URL не должен быть пустым');
    $v->rule('lengthMax', 'url', 255)->message('URL не должен превышать 255 символов');
    $v->rule('urlActive', 'url')->message('Некорректный URL');
    return ['result' => $v->validate(), 'error' => $v->errors()['url'][0] ?? []];
}
