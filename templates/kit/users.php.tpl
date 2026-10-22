<?php
/**
 * {{APP_NAME}} SSO — выгрузка пользователей для переноса в SSO.
 *
 * Это ЕДИНСТВЕННЫЙ файл набора, который надо доделать руками: ниже одна
 * функция, и в ней — запрос к вашей таблице пользователей. Всё остальное
 * (проверка подписи, страницы, формат ответа) уже написано и трогать его не
 * нужно.
 *
 * Как это работает: администратор SSO нажимает «Забрать людей», SSO приходит
 * сюда с подписью, вы отвечаете страницей JSON. Направление именно такое —
 * спрашивает SSO, отвечаете вы. Сами вы к SSO с пользователями не ходите.
 *
 * Положите файл рядом с остальными (sso/users.php), впишите ключ переноса в
 * config.php и добавьте адрес в манифест:
 *
 *   "users": { "url": "/sso/users.php", "count": 1234 }
 *
 * Файл отдаёт персональные данные. Он защищён подписью и обязан жить только
 * по https — по открытому каналу его читает кто угодно по дороге.
 */

require_once __DIR__ . '/bootstrap.php';
$config = sso_config();

/* ------------------------------------------------------------------
 * ЕДИНСТВЕННОЕ, ЧТО НУЖНО НАПИСАТЬ.
 *
 * Верните массив строк. Обязательно поле `id` — ВАШ внутренний номер
 * пользователя: по нему SSO узнаёт того же человека при повторном переносе,
 * даже если он сменил и почту, и фамилию. Номер обязан быть постоянным.
 *
 * Кроме него нужно хотя бы одно из двух — `email` или `login`: иначе человека
 * не по чему узнавать, и такая строка будет пропущена.
 *
 * `password_hash` отдавайте, только если это bcrypt ($2y$…) или argon2
 * ($argon2id$…). SSO примет их как есть и перехэширует при первом входе. md5,
 * sha1 и тем более открытый пароль отдавать НЕ надо — SSO их не примет, а
 * человек просто задаст пароль заново.
 *
 * `roles` — необязательный список слагов ролей человека У ВАС, тех же, что в
 * манифесте. SSO выдаст их по карте ролей или одноимённой роли вашего портала,
 * только в пределах вашего портала; повторный перенос роли не снимает.
 * ------------------------------------------------------------------ */
function sso_export_users(int $limit, int $offset): array
{
    // TODO: замените на запрос к своей базе.
    //
    // $pdo = new PDO('mysql:host=localhost;dbname=site;charset=utf8mb4', 'user', 'pass');
    // $st = $pdo->prepare('SELECT id, email, login, first_name, last_name, phone,
    //                             password AS password_hash, created_at, is_email_confirmed
    //                        FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT ? OFFSET ?');
    // $st->execute([$limit, $offset]);
    // $rows = [];
    // foreach ($st as $r) {
    //     $rows[] = [
    //         'id'             => (string)$r['id'],
    //         'email'          => $r['email'] ?: null,
    //         'login'          => $r['login'] ?: null,
    //         'email_verified' => (bool)$r['is_email_confirmed'],
    //         'first_name'     => $r['first_name'],
    //         'last_name'      => $r['last_name'],
    //         'phone'          => $r['phone'],
    //         'password_hash'  => $r['password_hash'],
    //         'created_at'     => $r['created_at'],
    //         // Роли человека у ВАС — те же слаги, что в манифесте (необязательно)
    //         'roles'          => [$r['role']],
    //     ];
    // }
    // return $rows;

    return [];
}

/** Сколько всего людей — чтобы SSO показал администратору масштаб. Необязательно. */
function sso_export_total(): ?int
{
    // return (int)$pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn();
    return null;
}

/* ==================================================================
 * Ниже трогать не нужно.
 * ================================================================== */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = static function (int $code, string $why): never {
    http_response_code($code);
    echo json_encode(['error' => $why], JSON_UNESCAPED_UNICODE);
    exit;
};

$secret = (string)($config['users_secret'] ?? '');
if ($secret === '' || str_starts_with($secret, '{{')) {
    $fail(500, 'users_secret не задан в config.php');
}

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
}
/* Заголовки X-SSO-* с версии 4.89; прежние X-TSOY-* принимаются до 5.0 —
   {{APP_NAME}} пока шлёт оба набора. */
$client = (string)($headers['x-sso-client'] ?? $headers['x-tsoy-client'] ?? '');
$ts     = (string)($headers['x-sso-timestamp'] ?? $headers['x-tsoy-timestamp'] ?? '');
$sig    = (string)($headers['x-sso-signature'] ?? $headers['x-tsoy-signature'] ?? '');

if ($client !== (string)($config['client_id'] ?? '')) $fail(403, 'чужой client_id');

/* Метка времени обязательна: без неё подслушанный запрос можно повторять
   вечно. Пять минут — запас на расхождение часов, не больше. */
if ($ts === '' || !ctype_digit($ts) || abs(time() - (int)$ts) > 300) $fail(403, 'метка времени не годится');

/* Подпись считается по СЫРОЙ строке запроса — по той, что подписывала другая
   сторона. Раньше она собиралась из уже обрезанных значений, и запрос с
   limit=1000 (контракт разрешает до 5000 за заход) давал «подпись не сошлась»:
   мы считали по limit=500, а подписан был limit=1000. Ошибка выглядит как
   проблема с ключом и уводит поиск совсем не туда. Обрезаем — после проверки. */
$query = (string)($_SERVER['QUERY_STRING'] ?? '');

$expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $query, $secret);
/* hash_equals, а не ===: обычное сравнение строк выходит на первом же
   несовпавшем байте, и по времени ответа подпись подбирается посимвольно. */
if (!hash_equals($expected, $sig)) $fail(403, 'подпись не сошлась');

/* Границы применяем после проверки подписи: они защищают нашу базу от
   запроса «отдай всё сразу», а не подпись от подделки. */
$limit  = min(500, max(1, (int)($_GET['limit'] ?? 200)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$users = sso_export_users($limit, $offset);
$total = sso_export_total();

echo json_encode(array_filter([
    'users' => array_values($users),
    'total' => $total,
], static fn($v) => $v !== null), JSON_UNESCAPED_UNICODE);
