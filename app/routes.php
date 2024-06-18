<?php

/**
 * @var App $app
 * @var Database $db
 */

use App\Database;
use Carbon\Carbon;
use DiDom\Document;
use GuzzleHttp\Exception\RequestException;
use Slim\App;
use GuzzleHttp\Client;
use Illuminate\Support;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ConnectException;

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'main.phtml');
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

    $urlsKeyed = collect($urls)->keyBy('id');
    $checksKeyed = collect($checks)->keyBy('id');

    $merged = $urlsKeyed->map(function ($item, $key) use ($checksKeyed) {
        return $checksKeyed->has($key) ? array_merge($item, $checksKeyed->get($key)) : $item;
    });
    $params = ['sites' => $merged->all()];

    return $this->get('renderer')->render($response, '/urls/index.phtml', $params);
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

    $normalizedUrl = mb_strtolower(parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST));
    $sql = "SELECT id FROM urls WHERE name=:normalizedUrl";
    $urlRecords = $this->get('db')->getFirst($sql, ['normalizedUrl' => $normalizedUrl]);
    if (!empty($urlRecords)) {
        $id = $urlRecords['id'];
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

$app->get('/urls/{url_id:[0-9]+}', function ($request, $response, $args) {
    $getUrlSql = "SELECT * FROM urls WHERE id=:id";
    $urlData = $this->get('db')->getFirst($getUrlSql, ['id' => $args['url_id']]);
    if (!$urlData) {
        $response = $response->withStatus(404);
        return $this->get('renderer')->render($response, '404.phtml');
    }
    $getChecksSql = "SELECT * FROM url_checks WHERE url_id=:id ORDER BY id DESC ";
    $checksData = $this->get('db')->getAll($getChecksSql, ['id' => $args['url_id']]);
    $params = [
        'urlId' => $urlData['id'],
        'siteUrl' => $urlData['name'],
        'created' => $urlData['created_at'],
        'checks' => $checksData
    ];
    return $this->get('renderer')->render($response, '/urls/show.phtml', $params);
})->setName('urls.show');

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($app) {
    $urlId = $args['url_id'];
    $target = $app->getRouteCollector()->getRouteParser()->urlFor('urls.show', ['url_id' => $urlId]);
    $urlName = $this->get('db')->getFirst("SELECT name FROM urls WHERE id=:url_id", ['url_id' => $urlId]);

    if (!$urlName) {
        $this->get('flash')->addMessage('danger', 'Ошибка запроса к бд');
        return $response->withStatus(302)->withHeader('Location', $target);
    }

    $date = Carbon::now()->toDateTimeString();
    $sql = "INSERT INTO url_checks(url_id, status_code, h1, title, description, created_at)
            VALUES (:url_id, :statusCode, :h1, :title, :content, :date);";

    $client = new Client();
    try {
        $guzzle_response = $client->get($urlName['name'], ['allow_redirects' => false]);
    } catch (ClientException|ServerException|RequestException|ConnectException $e) {
        $statusCode = $e->getCode() ?: 0;
        $message = $e->getMessage() ?: 'not connect';
        $this->get('db')->insert($sql, [
            'url_id' => $urlId,
            'statusCode' => $statusCode,
            'h1' => null,
            'title' => null,
            'content' => null,
            'date' => $date
        ]);
        $this->get('flash')->addMessage('danger', "$statusCode $message");
        return $response->withStatus(302)->withHeader('Location', $target);
    }

    try {
        $document = new Document($urlName['name'], true);
        $h1 = optional($document->first('h1'))->text();
        $title = optional($document->first('title'))->text();
        $content = optional($document->first('meta[name="description"]'))->attr('content');
    } catch (ErrorException|Exception $e) {
        $this->get('db')->insert($sql, [
            'url_id' => $urlId,
            'statusCode' => $guzzle_response->getStatusCode(),
            'h1' => null,
            'title' => null,
            'content' => 'Ошибка при обращении к сайту',
            'date' => $date
        ]);
        $this->get('flash')->addMessage('danger', $e->getMessage());
        return $response->withStatus(302)->withHeader('Location', $target);
    }

    $this->get('db')->insert($sql, [
        'url_id' => $urlId,
        'statusCode' => $guzzle_response->getStatusCode(),
        'h1' => (isset($h1)) ? Support\Str::limit($h1, 255) : null,
        'title' => (isset($title)) ? Support\Str::limit($title, 255) : null,
        'content' => (isset($content)) ? Support\Str::limit($content, 1000) : null,
        'date' => $date
    ]);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    return $response->withStatus(302)->withHeader('Location', $target);
})->setName('urls.checks.store');

$app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($request, $response) {
    $response = $response->withStatus(404);
    return $this->get('renderer')->render($response, '404.phtml');
});

function validateUrl(array $url): array
{
    $v = new Valitron\Validator($url);
    $v->rule('required', 'url')->message('URL не должен быть пустым');
    $v->rule('lengthMax', 'url', 255)->message('URL не должен превышать 255 символов');
    $v->rule('urlActive', 'url')->message('Некорректный URL');
    return ['result' => $v->validate(), 'error' => $v->errors()['url'][0] ?? []];
}
