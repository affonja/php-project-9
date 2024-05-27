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

$app->get('/', function ($request, $response) {
    $messages = $this->get('flash')->getMessages();
    $params = [
        'flash' => $messages
    ];
    return $this->get('renderer')->render($response, 'main.phtml', $params);
})->setName('main');

$app->get('/urls', function ($request, $response) use ($db) {
    $sql = "SELECT u.id AS id, u.name AS name, uc.status_code,v.last_check AS last_check
            FROM url_checks uc
                INNER JOIN (SELECT url_id, MAX(created_at) AS last_check
                     FROM url_checks GROUP BY url_id) AS v
                    ON v.url_id = uc.url_id AND v.last_check = uc.created_at
                RIGHT JOIN urls u ON u.id = v.url_id
            ORDER BY u.id DESC;";

    $sites = $db->getAll($sql, []);
    $params = ['sites' => $sites];
    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName('sites.list');

$app->post('/urls', function ($request, $response) use ($db) {
    $validationUrl = validateUrl();
    if (!$validationUrl['result']) {
        $message = $validationUrl['error'];
        return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', ['error' => $message]);
    }

    $url = $request->getParam('url')['name'];
    $normalizedUrl = parse_url($url, 0) . '://' . parse_url($url, 1);
    $sql = "SELECT id FROM urls WHERE name=:normalizedUrl";
    $isExist = !empty($db->getAll($sql, ['normalizedUrl' => $normalizedUrl]));
    if ($isExist) {
        $id = $db->getAll($sql, ['normalizedUrl' => $normalizedUrl])[0]['id'];
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withStatus(302)->withHeader('Location', "/urls/$id");
    }
    $date = Carbon::now()->toDateTimeString();
    $sql = "INSERT INTO urls(name, created_at) VALUES (:normalizedUrl, :date);";
    $id = $db->insert($sql, ['normalizedUrl' => $normalizedUrl, 'date' => $date]);

    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    return $response->withStatus(302)->withHeader('Location', "/urls/$id");
})->setName('url.add');

$app->get('/urls/{url_id}', function ($request, $response, $args) use ($db) {
    $messages = $this->get('flash')->getMessages();

    $getUrlSql = "SELECT * FROM urls WHERE id=:id";
    $urlData = $db->getAll($getUrlSql, ['id' => $args['url_id']])[0];
    $getChecksSql = "SELECT * FROM url_checks WHERE url_id=:id ORDER BY id DESC ";
    $checksData = $db->getAll($getChecksSql, ['id' => $args['url_id']]);
    $params = [
        'url_id' => $urlData['id'],
        'siteUrl' => $urlData['name'],
        'created' => $urlData['created_at'],
        'flash' => $messages,
        'checks' => $checksData
    ];
    return $this->get('renderer')->render($response, 'url.phtml', $params);
})->setName('url.info');

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($db) {
    $url_id = $args['url_id'];
    $url_name = $db->getAll("SELECT name FROM urls WHERE id=:url_id", ['url_id' => $url_id])[0]['name'];

    $client = new GuzzClient();
    try {
        $response = $client->get($url_name);
    } catch (GuzzExeption $e) {
        $this->get('flash')->addMessage('error', 'Ошибка при обращении к сайту:' . $e->getMessage());
        return $response->withStatus(302)->withHeader('Location', "/urls/$url_id");
    }
    $statusCode = $response->getStatusCode();
    $date = Carbon::now()->toDateTimeString();

    $document = new Document($url_name, true);
    $h1 = ($document->has('h1')) ? $document->find('h1')[0]->text() : '';
    $title = ($document->has('title')) ? $document->find('title')[0]->text() : '';
    $content = ($document->has('meta[name="description"]')) ?
        $document->find('meta[name="description"]')[0]->attr('content') : '';

    $sql = "INSERT INTO url_checks(url_id, status_code, h1, title, description, created_at)
            VALUES (:url_id, :statusCode, :h1, :title, :content, :date);";
    $db->insert($sql, [
        'url_id' => $url_id,
        'statusCode' => $statusCode,
        'h1' => $h1,
        'title' => $title,
        'content' => $content,
        'date' => $date
    ]);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    return $response->withStatus(302)->withHeader('Location', "/urls/$url_id");
})->setName('url.check');
