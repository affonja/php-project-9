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
SELECT url_id, status_code, MAX(created_at) AS last_check
FROM url_checks
GROUP BY url_id, status_code
ORDER BY url_id DESC;
SQL;
    $checks = $this->get('db')->getAll($sqlChecks, []);
    $sqlUrls = "SELECT id, name FROM urls ORDER BY id DESC;";
    $urls = $this->get('db')->getAll($sqlUrls, []);
    $mergedUrls = [];
    foreach ($urls as $url) {
        foreach ($checks as $check) {
            if ($url['id'] == $check['url_id']) {
                $url['status_code'] = $check['status_code'];
                $url['last_check'] = $check['last_check'];
                break;
            }
        }
        $mergedUrls[] = $url;
    }

    $params = ['sites' => $mergedUrls];

    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName('urls.store');

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
        $target = $app->getRouteCollector()->getRouteParser()->urlFor('url.show', ['url_id' => $id]);

        return $response->withStatus(302)->withHeader('Location', $target);
    }
    $date = Carbon::now()->toDateTimeString();
    $sql = "INSERT INTO urls(name, created_at) VALUES (:normalizedUrl, :date);";
    $id = $this->get('db')->insert($sql, ['normalizedUrl' => $normalizedUrl, 'date' => $date]);
    $target = $app->getRouteCollector()->getRouteParser()->urlFor('url.show', ['url_id' => $id]);
    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');

    return $response->withStatus(302)->withHeader('Location', $target);
})->setName('urls.add');

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
        'siteUrl' => $urlData[0]['name'],
        'created' => $urlData[0]['created_at'],
        'flash' => $messages,
        'checks' => $checksData
    ];
    return $this->get('renderer')->render($response, 'url.phtml', $params);
})->setName('url.show');

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($app) {
    $url_id = $args['url_id'];
    $target = $app->getRouteCollector()->getRouteParser()->urlFor('url.show', ['url_id' => $url_id]);
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
        $h1 = optional($document->find('h1')[0])->text();
        $title = optional($document->find('title')[0])->text();
        $content = optional($document->find('meta[name="description"]')[0])->attr('content');
        $this->get('db')->insert($sql, [
            'url_id' => $url_id,
            'statusCode' => $statusCode,
            'h1' => (isset($h1)) ? getShortString($h1) : null,
            'title' => (isset($title)) ? getShortString($title) : null,
            'content' => (isset($content)) ? getShortString($content, 1000) : null,
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
})->setName('url.check');

function getShortString(string $str, int $length = 255): string
{
    return substr($str, 0, $length);
}

function validateUrl(array $url): array
{
    $v = new Valitron\Validator($url);
    $v->rule('required', 'url')->message('URL не должен быть пустым');
    $v->rule('lengthMax', 'url', 255)->message('URL не должен превышать 255 символов');
    $v->rule('urlActive', 'url')->message('Некорректный URL');
    return ['result' => $v->validate(), 'error' => $v->errors()['url'][0] ?? []];
}
