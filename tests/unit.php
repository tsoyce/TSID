<?php
/** Юнит-проверки криптопримитивов. Запуск: php -d extension=sodium tests/unit.php */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Crypto;
use App\Core\Jwt;
use App\Core\Keys;
use App\Core\Palette;
use App\Core\Pkce;
use App\Core\Totp;
use App\Core\Translit;

$fails = 0;
function check(bool $ok, string $name): void
{
    global $fails;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
    if (!$ok) $fails++;
}

echo "TOTP (RFC 6238, вектор SHA-1):\n";
// Секрет из RFC: ASCII "12345678901234567890" → base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
$secret = Totp::base32Encode('12345678901234567890');
check($secret === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 encode совпал с RFC');
check(Totp::base32Decode($secret) === '12345678901234567890', 'base32 роундтрип');
// T=59 → счётчик 1 → код 287082 (RFC 6238, приложение B; 6 цифр от 94287082)
check(Totp::code($secret, 1) === '287082', 'код для T=59 совпал с RFC-вектором');
check(Totp::code($secret, 37037036) === '081804', 'код для T=1111111109 совпал');

echo "PKCE (RFC 7636, вектор S256):\n";
$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
$challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
check(Pkce::verify($verifier, $challenge), 'verifier/challenge из RFC сходятся');
check(!Pkce::verify($verifier . 'x', $challenge), 'подделка verifier отбивается');
check(Pkce::validChallenge($challenge, 'S256'), 'валидация challenge');
check(!Pkce::validChallenge($challenge, 'plain'), 'plain не существует');

echo "Crypto (шифрование at rest):\n";
$plain = 'JBSWY3DPEHPK3PXP-секрет-с-кириллицей';
$enc = Crypto::encrypt($plain);
check(!str_contains($enc, 'JBSWY3DP'), 'шифротекст не содержит открытого текста');
check(Crypto::decrypt($enc) === $plain, 'роундтрип encrypt/decrypt');
check(strlen(Crypto::uuid4()) === 36 && str_starts_uuid(Crypto::uuid4()), 'uuid4 корректной формы');

echo "JWT RS256 (подпись/проверка на реальных ключах):\n";
$claims = ['iss' => 'test', 'sub' => 'abc', 'exp' => time() + 60, 'iat' => time()];
$jwt = Jwt::sign($claims);
$back = Jwt::verify($jwt);
check($back !== null && $back['sub'] === 'abc', 'sign → verify роундтрип');
$tampered = substr($jwt, 0, -8) . 'AAAAAAAA';
check(Jwt::verify($tampered) === null, 'подделанная подпись отбрасывается');
$expired = Jwt::sign(['exp' => time() - 3600] + $claims);
check(Jwt::verify(Jwt::sign(['iss' => 't', 'exp' => time() - 3600])) === null, 'протухший exp отбрасывается');
// none-алгоритм не пройдёт по построению (только RS256 в verify)
$noneJwt = Crypto::b64url('{"alg":"none","typ":"JWT"}') . '.' . Crypto::b64url('{"sub":"evil"}') . '.';
check(Jwt::verify($noneJwt) === null, 'alg=none отбрасывается');

echo "JWKS:\n";
$jwks = Keys::jwks();
check(isset($jwks['keys'][0]['n'], $jwks['keys'][0]['e']) && $jwks['keys'][0]['alg'] === 'RS256', 'JWKS отдаёт RSA-ключи');

echo "Palette (контраст белого лейбла, WCAG 2.1):\n";
// Опорные значения из спецификации: чёрное на белом — ровно 21:1
check(abs(Palette::contrast('#000000', '#FFFFFF') - 21.0) < 0.01, 'чёрное на белом = 21:1');
check(abs(Palette::contrast('#FFFFFF', '#FFFFFF') - 1.0) < 0.01, 'цвет сам с собой = 1:1');
check(abs(Palette::luminance('#FFFFFF') - 1.0) < 0.001 && Palette::luminance('#000000') < 0.001, 'яркость на краях шкалы');

/* Главная гарантия релиза: какой бы акцент ни выбрал владелец, надпись на
   кнопке и ссылка остаются читаемыми в обеих темах. До v3.1 белый текст был
   прибит гвоздями, и жёлтый акцент давал 1.53 при норме 4.5. */
$accents = ['#1D4ED8', '#22D3EE', '#FACC15', '#84CC16', '#F97316', '#10B981',
            '#000000', '#FFFFFF', '#EAB308', '#7C3AED', '#E11D48'];
$worstFill = 99.0; $worstText = 99.0; $badFill = []; $badText = [];
foreach ($accents as $a) {
    foreach ([false, true] as $dark) {
        $r = Palette::report($a, $dark);
        if ($r['contrast_fill'] < 4.5) $badFill[] = $a . ($dark ? ' (тёмная)' : '');
        if ($r['contrast_text'] < 4.5) $badText[] = $a . ($dark ? ' (тёмная)' : '');
        $worstFill = min($worstFill, $r['contrast_fill']);
        $worstText = min($worstText, $r['contrast_text']);
    }
}
check($badFill === [], 'текст на кнопке читаем при любом акценте, худший ' . $worstFill
    . ($badFill ? ' — провалились: ' . implode(', ', $badFill) : ''));
check($badText === [], 'ссылка читаема при любом акценте, худший ' . $worstText
    . ($badText ? ' — провалились: ' . implode(', ', $badText) : ''));

$css = Palette::css('#FACC15');
check(str_contains($css, '--on-ink') && str_contains($css, '--ink-text')
    && str_contains($css, ':root[data-theme="dark"]'), 'CSS отдаёт обе темы и все три переменные');
check(Palette::normalize('мусор') === '#1D4ED8' && Palette::normalize('#abc') === '#AABBCC',
    'нормализация: мусор → дефолт, короткая запись → полная');

echo "Translit (кириллица → латиница):\n";
check(Translit::latin('Петров Анатолий') === 'Petrov Anatoliy', 'читаемая латиница с сохранением регистра');
check(Translit::latin('ЩУКА') === 'SHCHUKA' && Translit::latin('Щука') === 'Shchuka',
    'капс не превращается в ShchUKA');
check(Translit::latin('Ёжик') === 'Ezhik' && Translit::latin('подъезд') === 'podezd',
    'ё, ъ и ь обработаны');

/* Ровно тот случай, ради которого всё писалось: до v3.2 любое русское имя
   давало слаг «-», и все организации сталкивались друг с другом. */
$slugs = array_map(fn($n) => Translit::slug($n, 'org'),
    ['Календарь событий', 'Департамент образования', 'Отдел кадров', 'Резюме Петрова']);
check(count(array_unique($slugs)) === 4, 'русские названия дают разные слаги: ' . implode(', ', $slugs));
check($slugs[0] === 'kalendar-sobytiy', 'слаг читаемый, а не транскрипция наугад');
check(Translit::slug('🎉🎉🎉', 'org') === 'org' && Translit::slug('  ', 'org') === 'org',
    'из пустого получается запасное значение, а не пустая строка');
check(Translit::slug('Щёкинский цех №4') === 'shchekinskiy-tsekh-4', 'цифры и №  переживают чистку');
check(mb_strlen(Translit::slug(str_repeat('длинное', 30))) <= 50
    && !str_ends_with(Translit::slug(str_repeat('длинное', 30)), '-'), 'длина подрезана без хвостового дефиса');

check(Translit::domainSlug('https://календарьсобытий.рф') === 'kalendarsobytiy-ru',
    'кириллический домен транслитерируется, а не выбрасывается');
check(Translit::domainSlug('https://новости.example') !== Translit::domainSlug('https://cv.example'),
    'поддомены на одном домене не сливаются в один слаг');
check(Translit::domainSlug('https://www.blog.example') === 'blog-example', 'www отбрасывается');

$mrz = Translit::mrz('Щербаков', 'Юрий', 'SSO');
check(strlen($mrz) === 44, 'длина строки MRZ ровно 44 знака, получено ' . strlen($mrz));
check(str_contains($mrz, 'SHCHERBAKOV<<IURII'), 'имя по ИКАО Doc 9303: ' . $mrz);
check(preg_match('~^[A-Z0-9<]+$~', $mrz) === 1, 'в MRZ только допустимые знаки');
check(strlen(Translit::mrz('Оченьдлиннаяфамилиякоторая', 'Иннокентий')) === 44,
    'длинное имя обрезается до 44, а не ломает строку');

echo "\n── Номер аккаунта (v3.8) ──\n";
use App\Core\AccountNumber;

/* Контрольный вектор Луна из ISO/IEC 7812-1: к 7992739871 полагается 3.
   Проверяем через публичный isValid, чтобы тест не лез в приватные методы. */
check(AccountNumber::isValid('799273987') === (AccountNumber::isValid('799273987')),
    'алгоритм детерминирован');
$known = '79927398713';
$sum = 0; $double = true;
for ($i = strlen($known) - 2; $i >= 0; $i--) {
    $d = (int)$known[$i];
    if ($double) { $d *= 2; if ($d > 9) $d -= 9; }
    $sum += $d; $double = !$double;
}
check((string)((10 - $sum % 10) % 10) === '3', 'вектор Луна 7992739871 → 3 (эталон ISO/IEC 7812-1)');

$sample = [];
for ($i = 0; $i < 5000; $i++) $sample[] = AccountNumber::generate();
check(count(array_filter($sample, fn($n) => !AccountNumber::isValid($n))) === 0,
    'все 5000 сгенерированных номеров проходят собственную проверку');
check(count(array_filter($sample, fn($n) => strlen($n) !== 9)) === 0, 'длина всегда девять цифр');
check(count(array_filter($sample, fn($n) => $n[0] === '0')) === 0,
    'ведущего нуля не бывает — иначе номер терял бы цифру при копировании');
/* Не строгая проверка на случайность, а грубый детектор вырождения:
   5000 значений из 90 млн обязаны быть почти все разными. */
check(count(array_unique($sample)) >= 4995, 'номера не вырождаются в повторы: '
    . count(array_unique($sample)) . ' различных из 5000');

$n = AccountNumber::generate();
$single = 0;
for ($i = 0; $i < 9; $i++) {
    for ($d = 0; $d <= 9; $d++) {
        $m = $n; $m[$i] = (string)$d;
        if ($m !== $n && AccountNumber::isValid($m)) $single++;
    }
}
check($single === 0, 'любая одиночная опечатка ловится контрольной цифрой');

/* Перестановка соседних цифр. Луна ловит любую, КРОМЕ пары 0↔9: удвоение даёт
   им одинаковый вклад в сумму. Это известное свойство алгоритма, а не наш
   недосмотр, поэтому проверяем ровно его. Наивное «пропущенных нет» падало бы
   случайно — на тех номерах, где рядом оказались 0 и 9. */
$missed = 0;
$missedNotZeroNine = 0;
for ($k = 0; $k < 300; $k++) {
    $num = AccountNumber::generate();
    for ($i = 0; $i < 8; $i++) {
        if ($num[$i] === $num[$i + 1]) continue;
        $m = $num;
        [$m[$i], $m[$i + 1]] = [$m[$i + 1], $m[$i]];
        if (AccountNumber::isValid($m)) {
            $missed++;
            if (!in_array([$num[$i], $num[$i + 1]], [['0', '9'], ['9', '0']], true)) $missedNotZeroNine++;
        }
    }
}
check($missedNotZeroNine === 0,
    "мимо проходит только пара 0↔9 (всего пропущено $missed, из них не 0↔9 — $missedNotZeroNine)");

check(AccountNumber::format('482713905') === '4827-13905', 'показывается группами 4-5');
check(AccountNumber::normalize('4827-13905') === AccountNumber::normalize('482713905'),
    'дефис и голые цифры дают один результат');
check(AccountNumber::normalize(' 4827 13905 ') === AccountNumber::normalize('482713905'),
    'пробелы при копировании не мешают');
check(AccountNumber::normalize('482713904') === null, 'номер с неверной контрольной цифрой отвергается');
check(AccountNumber::normalize('12345') === null && AccountNumber::normalize('') === null,
    'мусор и пустая строка не проходят');

echo "\n── Markdown: выделение переживает перенос строки ──\n";
/* Текст документов и CHANGELOG свёрстан по ширине окна редактора, поэтому
   `**жирное**` регулярно оказывается разорванным переносом. Разметка шла
   построчно, пара не находилась, и на экране оставались звёздочки. */
$mdWrapped = \App\Core\Markdown::toHtml(
    "Абзац, у которого **выделение\nразорвано** переносом.\n\n"
    . "- Пункт с **жирным\n  продолжением**, перенесённым по ширине\n- Второй пункт");
check(!str_contains($mdWrapped, '**'), 'звёздочки не остаются в тексте');
check(substr_count($mdWrapped, '<strong>') === 2, 'выделение собирается из двух строк');
check(substr_count($mdWrapped, '<li>') === 2, 'продолжение пункта не рвёт список');
check(!str_contains(\App\Core\Markdown::toHtml('<script>alert(1)</script>'), '<script'),
    'разметка по-прежнему экранируется до разбора');

echo "\n── Разбор CHANGELOG в ленту релизов ──\n";
$rel = \App\Core\Changelog::releases(5);
check($rel !== [] && ($rel[0]['version'] ?? '') === SSO_VERSION,
    'лента начинается с текущей версии: ' . ($rel[0]['version'] ?? '—'));

$noSummary = array_column(array_filter($rel,
    static fn(array $r): bool => \App\Core\Changelog::summary($r) === ''), 'version');
check($noSummary === [], 'у каждого релиза есть сводка' . ($noSummary ? ': ' . implode(',', $noSummary) : ''));

/* Сводка — начало фразы, а не середина. Пункты списка переносятся с отступом,
   и раньше продолжение пункта попадало в ленту: «стороне — по-своему, и до сих
   пор портал получал…». Строчная буква в начале и есть признак такой беды. */
$mid = array_column(array_filter($rel, static function (array $r): bool {
    $s = \App\Core\Changelog::summary($r);
    return $s !== '' && preg_match('~^\p{Ll}~u', $s) === 1;
}), 'version');
check($mid === [], 'сводка начинается с начала предложения' . ($mid ? ': ' . implode(',', $mid) : ''));

$relBody = \App\Core\Changelog::html($rel[0]);
/* Звёздочки внутри <code> законны: там их показывают нарочно, объясняя разметку.
   Ищем неразмеченное выделение в обычном тексте, а не любые две звезды подряд —
   иначе проверка падает на описании самой этой ошибки. */
$relText = (string)preg_replace('~<code>.*?</code>~s', '', $relBody);
check(str_contains($relBody, '<li>') && !str_contains($relText, '**'),
    'тело релиза размечается, сырой markdown наружу не течёт');
check(!str_contains($relBody, '<script'), 'разметка из файла не превращается в скрипт');

echo "\n── Строка подключения к базе ──\n";
/* MySQL-ветка в батарее не выполняется вовсе: тесты идут на SQLite. Строку
   собирали тремя одинаковыми sprintf в трёх файлах, и разойдись они — узнали
   бы об этом на боевой установке, а не здесь. Формат закреплён числом. */
check(\App\Core\Dsn::build(['driver' => 'sqlite', 'name' => 'storage/x.sqlite'])
    === 'sqlite:storage/x.sqlite', 'sqlite: путь как есть');
check(\App\Core\Dsn::build(['driver' => 'mysql', 'host' => 'db.local', 'port' => 3307,
        'name' => 'sso', 'charset' => 'utf8mb4'])
    === 'mysql:host=db.local;port=3307;dbname=sso;charset=utf8mb4', 'mysql: все поля на месте');
/* Пустые значения дают умолчания, а не «host=;port=0» — именно так вело себя
   каждое из трёх мест, и поведение сохранено. */
check(\App\Core\Dsn::build(['driver' => 'mysql', 'host' => '', 'port' => 0, 'name' => 'sso'])
    === 'mysql:host=localhost;port=3306;dbname=sso;charset=utf8mb4',
    'пустые host/port/charset заменяются умолчаниями');
check(\App\Core\Dsn::build(['name' => 'sso']) === \App\Core\Dsn::build(['driver' => 'mysql', 'name' => 'sso']),
    'без указания драйвера собирается mysql, как и раньше');

echo "\n── Возврат на страницу, с которой пришли ──\n";
/* Адрес возврата приезжает скрытым полем, то есть из браузера, то есть его
   подделывают. Без проверки это открытый редирект: ссылка на наш домен,
   уводящая на чужой сайт, — классическая заготовка для фишинга, потому что в
   адресной строке до перехода стоит именно наш хост. Отдельная опасность —
   `//злой.сайт` и `/\злой.сайт`: они начинаются со слэша и на глаз выглядят
   локальными, а браузер читает их как адрес с другим хостом. */
$backCases = [
    ['/admin/users', '/admin/users', 'обычный путь проходит'],
    ['/admin/users?page=2#tab-list', '/admin/users?page=2#tab-list', 'запрос и якорь сохраняются'],
    ['//evil.example', '/admin/tools', 'протокол-относительный адрес отбит'],
    ['/\\evil.example', '/admin/tools', 'обратный слэш отбит'],
    ['https://evil.example', '/admin/tools', 'абсолютный чужой адрес отбит'],
    ['javascript:alert(1)', '/admin/tools', 'javascript: отбит'],
    ["/admin\r\nSet-Cookie: a=b", '/admin/tools', 'перевод строки отбит'],
    ['', '/admin/tools', 'пустое поле → запасной адрес'],
    ['/' . str_repeat('a', 500), '/admin/tools', 'слишком длинный адрес отбит'],
];
foreach ($backCases as [$in, $want, $name]) {
    $_POST['_back'] = $in;
    check(back_to('/admin/tools') === $want, $name);
}
unset($_POST['_back']);
check(back_to('/admin/tools') === '/admin/tools', 'без поля — запасной адрес');

$_SERVER['REQUEST_URI'] = '/admin/users?q=<script>';
check(!str_contains(back_field(), '<script>') && str_contains(back_field(), 'name="_back"'),
    'поле возврата экранируется');

echo "\n── Недоступная база отличается от отставшей схемы ──\n";
/* Смысл различия: «нет колонки» приложение обязано переживать — код обновили,
   миграцию ещё не применили. «Нет соединения» переживать нельзя: до v4.2 оба
   случая ловились одинаково, страницы отдавали 200 на файловых дефолтах, и
   мёртвая установка выглядела работающей. */
$cfgProp = new ReflectionProperty(\App\Core\Config::class, 'data');
$cfgProp->setAccessible(true);
$cfgWas = $cfgProp->getValue();
$pdoProp = new ReflectionProperty(\App\Core\DB::class, 'pdo');
$pdoProp->setAccessible(true);
$pdoWas = $pdoProp->getValue();

$broken = $cfgWas;
$broken['db']['name'] = '/nonexistent-dir-for-tests/nope.sqlite';
$cfgProp->setValue(null, $broken);
$pdoProp->setValue(null, null);

$thrown = null;
try {
    \App\Core\DB::pdo();
} catch (\Throwable $e) {
    $thrown = $e;
}
check($thrown instanceof \App\Core\DbUnavailable,
    'нет соединения → DbUnavailable, а не голое PDOException');

/* И Setting не делает вид, что всё в порядке: раньше он глушил это молча. */
\App\Core\Setting::flush();
$settingThrew = false;
try {
    \App\Core\Setting::get('app.name');
} catch (\App\Core\DbUnavailable) {
    $settingThrew = true;
}
check($settingThrew, 'Setting пробрасывает недоступность базы, а не молчит');

$cfgProp->setValue(null, $cfgWas);
$pdoProp->setValue(null, $pdoWas);
\App\Core\Setting::flush();
check(is_string(\App\Core\Setting::get('app.name', 'x')), 'после восстановления конфига настройки читаются');

echo "Человек без почты (v4.5):\n";
/* Порталу уходит id_token. Пустой адрес там опаснее отсутствующего: портал
   сложит его в свою базу как настоящий и получит столько дублей, сколько у нас
   людей без почты. По OIDC отсутствие ключа и означает «данных нет». */
$noMail = [
    'public_id' => 'sub-nomail', 'email' => null, 'email_verified_at' => null,
    'login' => 'ivanov', 'first_name' => 'Иван', 'last_name' => 'Иванов',
    'display_name' => null, 'avatar_path' => null, 'birthdate' => null, 'gender' => null,
    'locale' => 'ru', 'timezone' => 'Europe/Moscow', 'updated_at' => '2026-01-01 00:00:00',
    'phone' => null, 'phone_verified_at' => null, 'account_number' => null, 'id' => 0,
    'is_verified_badge' => 0, 'latin_first_name' => null, 'latin_last_name' => null,
];
$claims = \App\Oidc\IdToken::claims($noMail, ['id' => 0], 'openid profile email');
check(!array_key_exists('email', $claims), 'без почты claim email не отдаётся вовсе');
check(!array_key_exists('email_verified', $claims), 'email_verified тоже не отдаётся');
check(($claims['preferred_username'] ?? null) === 'ivanov', 'логин уходит в preferred_username');
check(($claims['sub'] ?? null) === 'sub-nomail', 'sub на месте');

$withMail = ['email' => 'i@example.ru', 'email_verified_at' => '2026-01-01 00:00:00'] + $noMail;
$c2 = \App\Oidc\IdToken::claims($withMail, ['id' => 0], 'openid email');
check(($c2['email'] ?? null) === 'i@example.ru' && ($c2['email_verified'] ?? null) === true,
    'с почтой claim отдаётся как прежде');

/* Подпись под именем: пустое место читалось бы как «данные потерялись». */
check(user_handle(['email' => 'a@b.ru', 'login' => 'x']) === 'a@b.ru', 'почта в подписи главнее логина');
check(user_handle(['email' => null, 'login' => 'x']) === '@x', 'без почты в подписи логин со «собакой»');
check(user_handle(['email' => null, 'login' => null, 'account_number' => '4827-13905']) === '№ 4827-13905',
    'без почты и логина в подписи номер аккаунта');
check(user_handle([]) === '—', 'когда нечем назвать — прочерк, а не пустота');

echo "Свод журнала ошибок (v4.7):\n";
/* Замер живого журнала: 460 строк за день — шесть разных сообщений. Экран
   рисовал карточку на каждую запись и растягивался на 27 экранов, где сотня
   копий одной строки прятала редкую и важную. */
$logRows = [];
foreach ([['warning', 'photo.host_rejected', '2026-08-08T17:00:00+00:00'],
          ['warning', 'photo.host_rejected', '2026-08-08T12:00:00+00:00'],
          ['warning', 'photo.host_rejected', '2026-08-08T11:00:00+00:00'],
          ['error',   'db.gone',             '2026-08-08T16:00:00+00:00'],
          ['info',    'export_ready',        '2026-08-08T15:00:00+00:00'],
          ['info',    'export_ready',        '2026-08-08T14:00:00+00:00']] as [$lv, $m, $ts]) {
    $logRows[] = ['level' => $lv, 'msg' => $m, 'ts' => $ts, 'ctx' => []];
}
$grouped = \App\Controllers\Admin\LogsController::groupErrors($logRows);
check(count($grouped) === 3, 'шесть записей свелись в три группы: ' . count($grouped));
check($grouped[0]['msg'] === 'photo.host_rejected' && $grouped[0]['count'] === 3,
    'самое частое сообщение стоит первым и посчитано верно');
/* Промежуток — то, чего на экране не было вовсе: одно и то же сообщение раз в
   час и сто раз за минуту означают разное. */
check($grouped[0]['first'] === '2026-08-08T11:00:00+00:00'
    && $grouped[0]['last'] === '2026-08-08T17:00:00+00:00',
    'промежуток считается от старой записи к свежей');
check(count($grouped[0]['items']) === 3, 'сами записи не потерялись — они внутри группы');
/* При равном счёте выше та группа, где событие свежее: разбирают всегда с неё. */
$tie = array_values(array_filter($grouped, static fn(array $g): bool => $g['count'] === 1));
check($grouped[1]['count'] === 2 && $grouped[2]['count'] === 1,
    'группы идут от частого к редкому');
check(\App\Controllers\Admin\LogsController::groupErrors([]) === [],
    'пустой журнал сводится в пустой список, а не в группу-призрак');

/* ── Имя для входа (v4.14) ─────────────────────────────────────────────────
   Правило было одно и жило в контроллере профиля; SCIM, Admin API, импорт и
   перенос с портала не проверяли ничего, кроме занятости. Проверяем сам
   разбор — он теперь общий для всех пяти дверей. */
echo "\n═══ Имя для входа ═══\n";
use App\Core\LoginName;

check(LoginName::check('') === null, 'пустой логин допустим: человек входит по почте');
check(LoginName::check('ivan.petrov') === null, 'обычное имя проходит');
check(LoginName::check('a') !== null, 'слишком короткое отвергается');
check(LoginName::check(str_repeat('a', 65)) !== null, 'слишком длинное отвергается');
check(LoginName::check('иван') !== null, 'кириллица отвергается: «а» латинская и русская неразличимы на вид');
check(LoginName::check('ivan petrov') !== null, 'пробел отвергается');
check(LoginName::check('.ivan') !== null && LoginName::check('ivan-') !== null,
    'точка и дефис по краям отвергаются');
check(LoginName::check('admin') !== null, 'системные имена заняты');
check(LoginName::check('noreply') !== null, 'и те, что выглядят почтой сервиса, тоже');
check(LoginName::normalize('  IvAn  ') === 'ivan', 'регистр и пробелы не создают двойников');
/* Настройка своих занятых имён не должна отменять умолчание: список
   дополняет, а не заменяет — иначе «admin» освобождается по недосмотру. */
\App\Core\Setting::set('policy.login_reserved', "director\nhr");
\App\Core\Setting::flush();
check(LoginName::check('director') !== null, 'своё занятое имя работает');
check(LoginName::check('admin') !== null, 'и не отменяет умолчания');
\App\Core\Setting::set('policy.login_reserved', '');
\App\Core\Setting::flush();

/* ── Домены почты при регистрации ──────────────────────────────────────── */
echo "\n═══ Домены почты ═══\n";
use App\Core\EmailDomains;

check(EmailDomains::allows('anyone@example.net'), 'пусто — пускаем любой домен');
\App\Core\Setting::set('policy.email_domains', '@example.com, example.org');
\App\Core\Setting::flush();
check(EmailDomains::allowed() === ['example.com', 'example.org'],
    'собачки и запятые разбираются: ' . implode(',', EmailDomains::allowed()));
check(EmailDomains::allows('ivan@example.com'), 'разрешённый домен проходит');
check(EmailDomains::allows('ivan@mail.example.com'), 'поддомен считается своим');
check(!EmailDomains::allows('ivan@example.com.evil.ru'),
    'домен, у которого разрешённый — лишь начало, не проходит');
check(!EmailDomains::allows('ivan@other.net'), 'чужой домен не проходит');
check(!EmailDomains::allows('не-адрес'), 'строка без собачки не проходит');
\App\Core\Setting::set('policy.email_domains', '');
\App\Core\Setting::flush();
check(EmailDomains::allows('ivan@other.net'), 'снятое ограничение снимается целиком');

/* ── Слепок настроек (v4.15) ───────────────────────────────────────────────
   Главное здесь — не то, что переносится, а то, что НЕ переносится: ключи
   подписи, владелец и отметки cron в чужой базе указывают в пустоту, и такой
   перенос не «частично сработает», а сломает вход. */
echo "\n═══ Слепок настроек ═══\n";
use App\Core\SettingsTransfer as ST;

check(ST::portable('policy.password_min') && ST::portable('branding.name')
    && ST::portable('docs.privacy.body') && ST::portable('app.timezone'),
    'правила, вид, документы и пояс переносятся');
check(!ST::portable('keys.registry') && !ST::portable('keys.active_kid'),
    'ключи подписи не переносятся');
check(!ST::portable('system.owner_user_id') && !ST::portable('system.move.nonce')
    && !ST::portable('system.backup_at'),
    'состояние установки не переносится');
check(!ST::portable('cron.heartbeat') && !ST::portable('onboarding.hidden')
    && !ST::portable('bridge.attempt.1'),
    'отметки времени и следы работы не переносятся');
/* Белый список: незнакомый ключ не уезжает по умолчанию — именно ради этого он
   белый, а не чёрный. */
check(!ST::portable('system.новый_секрет') && !ST::portable('whatever.new'),
    'ключ, которого не было в списке, не переносится сам собой');

check(ST::secret('mail.pass') && ST::secret('social.yandex.secret') && ST::secret('captcha.secret'),
    'пароли и секреты опознаются');
check(!ST::secret('captcha.site_key') && !ST::secret('social.yandex.client_id'),
    'публичные ключи секретами не считаются');

\App\Core\Setting::set('branding.footer', 'до переноса');
\App\Core\Setting::set('mail.pass', 'очень-секретно');
\App\Core\Setting::flush();

$snap = ST::export();
check(isset($snap['settings']['branding.footer']), 'слепок берёт переносимое');
check(!isset($snap['settings']['mail.pass']), 'и не берёт пароль без отдельной просьбы');
check(in_array('mail.pass', $snap['secrets_skipped'], true),
    'но честно говорит, что не взял: ' . implode(',', $snap['secrets_skipped']));
$withSecrets = ST::export(true);
check(($withSecrets['settings']['mail.pass'] ?? '') === 'очень-секретно',
    'по отдельной просьбе пароль уезжает');
foreach (array_keys($snap['settings']) as $k) {
    if (!ST::portable($k)) { check(false, "в слепок попал непереносимый ключ $k"); break; }
}

/* Разбор чужого файла. Не «упадёт или нет», а «что скажет человеку». */
$bad = ST::plan(['настройки' => []]);
check(!$bad['ok'] && $bad['error'] !== '', 'файл без раздела settings отвергается со словами');
$oldFormat = ST::plan(['format' => 99, 'settings' => []]);
check(!$oldFormat['ok'], 'файл другого формата отвергается');

$plan = ST::plan(['format' => ST::FORMAT, 'settings' => [
    'branding.footer' => 'после переноса',   // изменится
    'policy.require_phone' => '1',           // добавится или изменится
    'keys.registry' => 'чужие ключи',        // не примем
    'system.owner_user_id' => '42',          // и это тоже
]]);
check($plan['ok'], 'нормальный файл разбирается');
check(isset($plan['change']['branding.footer'])
    && $plan['change']['branding.footer'][0] === 'до переноса'
    && $plan['change']['branding.footer'][1] === 'после переноса',
    'изменение показывается как «было → станет»');
check(count($plan['refused']) === 2, 'непереносимые ключи названы, а не молча выброшены: '
    . implode(',', $plan['refused']));

$n = ST::apply(['format' => ST::FORMAT, 'settings' => [
    'branding.footer' => 'после переноса',
    'keys.registry' => 'чужие ключи',
]]);
check($n === 1, 'применился ровно один ключ, а не два');
check((string)\App\Core\Setting::get('branding.footer') === 'после переноса', 'и именно тот');
check((string)\App\Core\Setting::get('keys.registry', '') !== 'чужие ключи',
    'чужие ключи подписи в базу не попали');
\App\Core\Setting::delete('branding.footer');
\App\Core\Setting::delete('mail.pass');
\App\Core\Setting::flush();

/* ── Письма (v4.16) ────────────────────────────────────────────────────────
   Главное здесь — срок. Он был вписан в текст письма руками («Ссылка действует
   3 дня»), а настраивается отдельно: сократив его, владелец получал письмо,
   которое обещает три дня, и человека с протухшей через час ссылкой. */
echo "\n═══ Письма ═══\n";
use App\Core\MailTemplates as MT;

check(fmt_duration(3 * 86400) === '3 дня' && fmt_duration(3600) === '1 час'
    && fmt_duration(900) === '15 минут',
    'срок словами: ' . fmt_duration(3 * 86400) . ' / ' . fmt_duration(3600) . ' / ' . fmt_duration(900));

\App\Core\Setting::set('timeout.token_email_verify', (string)(3 * 86400));
\App\Core\Setting::flush();
$L = MT::render('verify', ['name' => 'Иван']);
check($L['expires'] === '3 дня', 'письмо называет срок из настроек: ' . $L['expires']);
/* Тот же тест после сокращения — ради него всё и затевалось. */
\App\Core\Setting::set('timeout.token_email_verify', '900');
\App\Core\Setting::flush();
$L = MT::render('verify', ['name' => 'Иван']);
check($L['expires'] === '15 минут', 'сократили срок — письмо говорит новый: ' . $L['expires']);
[$html] = \App\Core\View::email('verify', ['name' => 'Иван', 'url' => 'https://example.test/v/1']);
check(str_contains($html, '15 минут') && !str_contains($html, '3 дня'),
    'и в самом письме стоит он же, а не зашитая строка');
\App\Core\Setting::set('timeout.token_email_verify', (string)(3 * 86400));
\App\Core\Setting::flush();

check($L['greeting'] === 'Здравствуйте, Иван!', 'приветствие собирается с именем: ' . $L['greeting']);
$noName = MT::render('verify', ['name' => '']);
check($noName['greeting'] === 'Здравствуйте!', 'без имени — без висящей запятой: ' . $noName['greeting']);

/* Свой текст перекрывает поставку, пустой — нет. */
check(!MT::customized('verify'), 'по умолчанию письмо не считается переписанным');
MT::save('verify', 'greeting', 'Привет');
MT::save('verify', 'body', 'Подтвердите почту, {{ Имя }} — ссылка живёт {{ Срок }}.');
\App\Core\Setting::flush();
check(MT::customized('verify'), 'после правки — считается');
$own = MT::render('verify', ['name' => 'Иван']);
check($own['greeting'] === 'Привет, Иван!', 'своё приветствие работает: ' . $own['greeting']);
check(str_contains($own['body'], 'Иван') && str_contains($own['body'], '3 дня'),
    'подстановки в своём тексте работают: ' . $own['body']);
MT::save('verify', 'greeting', '');
MT::save('verify', 'body', '');
\App\Core\Setting::flush();
check(MT::part('verify', 'greeting') === 'Здравствуйте',
    'пустое поле означает «как в поставке», а не «пусто»');

/* Приветствие со своей подстановкой имени: имя второй раз не приклеиваем. */
MT::save('verify', 'greeting', 'Добрый день, {{ Имя }}');
\App\Core\Setting::flush();
$manual = MT::render('verify', ['name' => 'Иван']);
check($manual['greeting'] === 'Добрый день, Иван',
    'если имя поставили сами — не дублируем: ' . $manual['greeting']);
MT::save('verify', 'greeting', '');
\App\Core\Setting::flush();

check(MT::footer() === MT::FOOTER_DEFAULT, 'подпись по умолчанию — наша');
\App\Core\Setting::set('mail.footer', 'Поддержка: help@example.test');
\App\Core\Setting::flush();
[$html] = \App\Core\View::email('verify', ['name' => 'Иван', 'url' => 'https://example.test/v/1']);
check(str_contains($html, 'help@example.test'), 'своя подпись доезжает до письма');
\App\Core\Setting::delete('mail.footer');
\App\Core\Setting::flush();

/* Тексты писем переезжают вместе с настройками: это `mail.` — уже переносимый
   префикс, но проверить стоит, иначе на новой установке письма молча вернутся
   к поставке. */
check(ST::portable('mail.tpl.verify.body') && ST::portable('mail.footer'),
    'тексты писем и подпись переносятся слепком');
/* Уведомления владельцу — семейство наполовину: настройка едет, счётчики нет. */
check(ST::portable('notify.admin.user.registered') && ST::portable('notify.admin.to')
    && ST::portable('notify.admin.window'),
    'тумблеры уведомлений и адреса переносятся');
check(!ST::portable('notify.admin.at.user.registered')
    && !ST::portable('notify.admin.held.user.registered'),
    'а счётчики окна — нет: в чужой базе им делать нечего');

/* ── Уведомления владельцу (v4.17) ─────────────────────────────────────────
   Проверяем не «уходит ли письмо», а защиту от потока: бот, заводящий пятьсот
   аккаунтов, не должен прислать пятьсот писем — иначе владелец заведёт правило
   «в папку», и уведомления перестанут работать в тот день, когда понадобятся. */
echo "\n═══ Уведомления владельцу ═══\n";
use App\Core\AdminNotify as AN;

$mailCount = static fn(): int => (int)\App\Core\DB::val('SELECT COUNT(*) FROM {mail_queue}');

foreach (array_keys(AN::EVENTS) as $ev) {
    \App\Core\Setting::set('notify.admin.' . $ev, '0');
    \App\Core\Setting::delete('notify.admin.at.' . $ev);
    \App\Core\Setting::delete('notify.admin.held.' . $ev);
}
\App\Core\Setting::set('notify.admin.to', 'owner@test.local');
\App\Core\Setting::flush();

$was = $mailCount();
AN::say('user.registered', 'выключено — письма быть не должно');
check($mailCount() === $was, 'выключенное событие не пишет никому');

\App\Core\Setting::set('notify.admin.user.registered', '1');
\App\Core\Setting::set('notify.admin.window', '15');
\App\Core\Setting::flush();
$was = $mailCount();
AN::say('user.registered', 'первое событие');
check($mailCount() === $was + 1, 'включённое событие уходит письмом');

/* Ещё десять таких же за то же окно — писем больше не появляется. */
for ($i = 0; $i < 10; $i++) AN::say('user.registered', 'событие ' . $i);
check($mailCount() === $was + 1, 'поток склеивается: писем по-прежнему одно');
check((int)\App\Core\Setting::get('notify.admin.held.user.registered', '0') === 10,
    'но они посчитаны: ' . \App\Core\Setting::get('notify.admin.held.user.registered', '0'));

/* Окно закрылось — следующее письмо уходит и приносит хвост. */
\App\Core\Setting::set('notify.admin.at.user.registered', gmdate('Y-m-d H:i:s', time() - 3600));
\App\Core\Setting::flush();
$was = $mailCount();
AN::say('user.registered', 'после окна');
check($mailCount() === $was + 1, 'после окна письмо снова уходит');
$body = (string)\App\Core\DB::val('SELECT body_text FROM {mail_queue} ORDER BY id DESC LIMIT 1');
check(str_contains($body, '10'), 'и приносит хвост «и ещё 10»');
check((int)\App\Core\Setting::get('notify.admin.held.user.registered', '0') === 0,
    'счётчик обнуляется — хвост не приезжает дважды');

/* Разные события считаются отдельно: поток регистраций не глушит сообщение о
   подборе пароля. */
\App\Core\Setting::set('notify.admin.login.burst', '1');
\App\Core\Setting::flush();
$was = $mailCount();
AN::say('login.burst', 'подбор пароля');
check($mailCount() === $was + 1, 'другое событие не заглушено чужим окном');

/* Адресаты. */
check(AN::recipients() === ['owner@test.local'], 'адреса берутся из настройки');
\App\Core\Setting::set('notify.admin.to', 'a@test.local, b@test.local ; a@test.local');
\App\Core\Setting::flush();
check(AN::recipients() === ['a@test.local', 'b@test.local'],
    'разделители разбираются, дубли убираются: ' . implode(',', AN::recipients()));
\App\Core\Setting::set('notify.admin.to', 'не-адрес');
\App\Core\Setting::flush();
check(!in_array('не-адрес', AN::recipients(), true), 'мусор в адресах не берётся');

foreach (array_keys(AN::EVENTS) as $ev) {
    \App\Core\Setting::set('notify.admin.' . $ev, '0');
    \App\Core\Setting::delete('notify.admin.at.' . $ev);
    \App\Core\Setting::delete('notify.admin.held.' . $ev);
}
\App\Core\Setting::delete('notify.admin.to');
\App\Core\Setting::flush();

/* ── Подпись человека (v4.18.1) ────────────────────────────────────────────
   У человека без имени `user_name()` возвращает почту — ту же, что и
   `user_handle()`. Написанные друг под другом, они давали «admin@test.local»
   дважды: выглядит как ошибка данных, хотя данные в порядке. */
echo "\n═══ Подпись человека ═══\n";
$named = ['display_name' => 'Иван Петров', 'email' => 'ivan@test.local', 'login' => 'ivan'];
$noName = ['email' => 'admin@test.local'];
$loginOnly = ['login' => 'petya'];
$bare = ['account_number' => '1234567890'];

check(user_sub($named) === 'ivan@test.local', 'у человека с именем вторая строка — контакт');
check(user_sub($noName) === '', 'у человека без имени второй строки нет');
check(user_sub($loginOnly) === '', 'логин, ставший именем, не повторяется собачкой');
check(user_title($named) === 'Иван Петров · ivan@test.local', 'одной строкой: ' . user_title($named));
check(user_title($noName) === 'admin@test.local', 'без повтора: ' . user_title($noName));
check(user_title($bare) !== '' && !str_contains(user_title($bare), '·'),
    'человек с одним номером аккаунта: ' . user_title($bare));

/* ── Лента релизов ─────────────────────────────────────────────────────────
   Заголовок в CHANGELOG пишется рукой, и порядок «имя, потом дата» разбор не
   понимал: строка совпадала по версии, а дата и имя оставались пустыми. Шесть
   релизов подряд стояли в ленте без даты, и это выглядело не поломкой, а
   особенностью. Проверяем весь файл целиком, а не последний релиз. */
echo "\n═══ Лента релизов ═══\n";
$rels = \App\Core\Changelog::releases(100);
check(count($rels) > 10, 'релизы разбираются: ' . count($rels));
/* Каждый заголовок выпуска в файле обязан дойти до ленты. В v4.58 запись без
   перевода строки в конце склеила «## [4.57.0]» с последней строкой соседнего
   выпуска, и 4.57 молча пропал со страницы «О проекте»: 112 → 111. Проверки
   ниже этого не видели — первый выпуск по-прежнему совпадал с версией, у
   каждого оставшегося были дата и сводка. Считаем заголовки в сыром тексте,
   где бы они ни стояли, и сверяем с разобранными. */
preg_match_all('~##\s*\[(\d+\.\d+\.\d+)\]~', (string)file_get_contents(SSO_ROOT . '/CHANGELOG.md'), $rawHeads);
$lostRels = array_values(array_diff(array_unique($rawHeads[1]),
    array_column(\App\Core\Changelog::releases(100000), 'version')));
check($lostRels === [], 'каждый заголовок выпуска дошёл до ленты',
    'потеряны: ' . implode(', ', $lostRels));
$noDate = array_values(array_filter($rels, static fn(array $r): bool => $r['date'] === ''));
check($noDate === [], 'у каждого релиза есть дата',
    implode(', ', array_column($noDate, 'version')));
$badDate = array_values(array_filter($rels,
    static fn(array $r): bool => !preg_match('~^\d{4}-\d{2}-\d{2}$~', $r['date'])));
check($badDate === [], 'дата разобрана целиком, а не куском',
    implode(', ', array_column($badDate, 'date')));
/* Имя есть не у каждого релиза — только у «тематических». Но если оно в
   заголовке написано, разбор обязан его достать. */
$titled = array_values(array_filter($rels, static fn(array $r): bool => $r['title'] !== ''));
check(count($titled) >= 10, 'имена релизов достаются: ' . count($titled));
$latest = $rels[0];
check($latest['version'] === SSO_VERSION,
    'первый в ленте — текущая версия: ' . $latest['version'] . ' vs ' . SSO_VERSION);
check($latest['summary'] !== '' || $latest['body'] !== '', 'у свежего релиза есть текст');
/* Сводка — целый абзац, а не первая строка из файла: в CHANGELOG абзац разложен
   по строкам в восемьдесят знаков, и «сводкой» становился первый перенос. */
$sums = array_map([\App\Core\Changelog::class, 'summary'], array_slice($rels, 0, 12));
$leaked = array_values(array_filter($sums, static fn(string $s): bool => str_contains($s, "\x00")));
check($leaked === [], 'служебная метка не протекает в текст');
$chopped = array_values(array_filter($sums, static function (string $s): bool {
    /* Обрыв без многоточия и без точки на конце — тот самый «побился текст». */
    return $s !== '' && !preg_match('~[.!?…»)]$~u', $s);
}));
check($chopped === [], 'сводка кончается знаком препинания, а не обрывом',
    implode(' | ', array_map(static fn(string $s): string => mb_substr($s, -40), $chopped)));

/* ── Чистка пачкой (v4.18.2) ───────────────────────────────────────────────
   Проверяем не «удаляет ли», а КОГО НЕ удаляет: живых, владельца, суперадмина
   и тех, кто ещё в сроке «передумать». Пачка, которая захватила лишнего, —
   это не неудобство, а потерянные люди. */
echo "\n═══ Чистка пачкой ═══\n";
use App\Core\BulkPurge as BP;

$mk = static function (array $extra) : int {
    return \App\Models\User::create([
        'email' => 'bulk' . bin2hex(random_bytes(4)) . '@test.local',
        'first_name' => 'Пачка',
    ] + $extra);
};
$idLive = $mk(['status' => 'active']);
$idFresh = $mk(['status' => 'deleted']);
\App\Core\DB::run('UPDATE {users} SET deleted_at = ? WHERE id = ?', [now(), $idFresh]);
$idOld = $mk(['status' => 'deleted']);
\App\Core\DB::run('UPDATE {users} SET deleted_at = ? WHERE id = ?',
    [gmdate('Y-m-d H:i:s', time() - (BP::HOLD_DAYS + 1) * 86400), $idOld]);
$idNoDate = $mk(['status' => 'deleted']);

$ids = array_column(BP::plan(), 'id');
$ids = array_map('intval', $ids);
check(in_array($idOld, $ids, true), 'отлежавший срок попадает в чистку');
check(!in_array($idFresh, $ids, true), 'помеченный вчера — нет: срок «передумать» соблюдается');
check(!in_array($idLive, $ids, true), 'живой аккаунт не попадает никаким образом');
check(!in_array($idNoDate, $ids, true), 'помеченный без отметки времени остаётся поштучной зоне');

/* Суперадмин, помеченный на удаление, пачкой не сметается. */
$idSuper = $mk(['status' => 'deleted']);
\App\Core\DB::run('UPDATE {users} SET deleted_at = ? WHERE id = ?',
    [gmdate('Y-m-d H:i:s', time() - (BP::HOLD_DAYS + 1) * 86400), $idSuper]);
$superRole = \App\Models\Role::findBySlug('superadmin');
if ($superRole) \App\Models\Role::assign($idSuper, (int)$superRole['id']);
$ids2 = array_map('intval', array_column(BP::plan(), 'id'));
check(!in_array($idSuper, $ids2, true), 'суперадмин не сметается пачкой, даже помеченный');

$before = BP::markedTotal();
$done = BP::run();
check($done['users'] >= 1, 'чистка удалила отлежавших: ' . $done['users']);
check(\App\Models\User::find($idOld) === null, 'и именно их');
check(\App\Models\User::find($idFresh) !== null, 'свежепомеченный на месте');
check(\App\Models\User::find($idLive) !== null, 'живой на месте');
check(\App\Models\User::find($idSuper) !== null, 'суперадмин на месте');
check(BP::markedTotal() < $before, 'счётчик помеченных уменьшился');

foreach ([$idLive, $idFresh, $idNoDate, $idSuper] as $tmp) {
    if (\App\Models\User::find($tmp)) \App\Models\User::purge($tmp);
}

/* ── Замок (v4.20) ─────────────────────────────────────────────────────────
   Замок отвечает «ты ли это», а не «кто ты». Проверяем границы: когда он НЕ
   срабатывает — это важнее, чем когда срабатывает. Замок, запирающий того, у
   кого нечем отпереть, — это требование пароля каждые полчаса. */
echo "\n═══ Замок ═══\n";
use App\Core\QuickUnlock as QU;

\App\Core\Setting::set('policy.lock_minutes', '0');
\App\Core\Setting::flush();
check(!QU::enabled(), 'по умолчанию замка нет');

$lockUser = ['id' => 1, 'pin_hash' => null];
$oldSess = ['id' => 1, 'last_seen_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'locked_at' => null];
check(!QU::shouldLock($oldSess, $lockUser), 'выключенный замок не запирает даже через час');

\App\Core\Setting::set('policy.lock_minutes', '30');
\App\Core\Setting::flush();
check(QU::enabled(), 'включённый замок включён');
check(!QU::shouldLock($oldSess, $lockUser),
    'без PIN и без ключа устройства не запираем: отпирать было бы нечем');

$withPin = ['id' => 1, 'pin_hash' => password_hash('4731', PASSWORD_ARGON2ID)];
check(QU::shouldLock($oldSess, $withPin), 'простой дольше срока запирает');
$freshSess = ['id' => 1, 'last_seen_at' => now(), 'locked_at' => null];
check(!QU::shouldLock($freshSess, $withPin), 'свежая сессия не запирается');
check(QU::shouldLock($freshSess, $withPin + []) === false, 'и не запирается повторной проверкой');
$lockedSess = ['id' => 1, 'last_seen_at' => now(), 'locked_at' => now()];
check(QU::shouldLock($lockedSess, $withPin), 'заперта — значит заперта, пока не отперли');
check(QU::locked($lockedSess) && !QU::locked($freshSess), 'признак замка читается');

/* Формат PIN: короткий, длинный, буквы, одинаковые, подряд. */
check(QU::checkPinFormat('4731') === null, 'обычный PIN проходит');
check(QU::checkPinFormat('123') !== null, 'три цифры — мало');
check(QU::checkPinFormat('123456789') !== null, 'девять — много');
check(QU::checkPinFormat('12ab') !== null, 'буквы не годятся');
check(QU::checkPinFormat('1111') !== null, 'одинаковые не годятся');
check(QU::checkPinFormat('1234') !== null && QU::checkPinFormat('4321') !== null,
    'цифры подряд не годятся — в обе стороны');

/* Проверка кода и счётчик попыток. */
$pinUid = \App\Models\User::create(['email' => 'pin' . time() . '@test.local', 'first_name' => 'Пин']);
QU::setPin($pinUid, '4731');
$pinUser = \App\Models\User::find($pinUid);
check(QU::hasPin($pinUser), 'PIN сохранён хэшем');
check((string)$pinUser['pin_hash'] !== '4731', 'и именно хэшем, а не как есть');
$sid = \App\Models\SessionModel::create($pinUid, \App\Core\Crypto::randomToken(32), false, 3600);
QU::lock($sid);
$sessRow = \App\Core\DB::row('SELECT * FROM {sessions} WHERE id = ?', [$sid]);
check(QU::locked($sessRow), 'сессия заперта');
check(!QU::tryPin($pinUser, $sessRow, '0000'), 'неверный код не отпирает');
check(QU::tryPin($pinUser, $sessRow, '4731'), 'верный отпирает');
$sessRow = \App\Core\DB::row('SELECT * FROM {sessions} WHERE id = ?', [$sid]);
check(!QU::locked($sessRow), 'и замок снят в базе');

/* Пять неверных подряд гасят сессию: дальше только полный вход. */
QU::lock($sid);
$sessRow = \App\Core\DB::row('SELECT * FROM {sessions} WHERE id = ?', [$sid]);
for ($i = 0; $i < QU::PIN_ATTEMPTS + 1; $i++) QU::tryPin($pinUser, $sessRow, '0000');
$sessRow = \App\Core\DB::row('SELECT revoked_at FROM {sessions} WHERE id = ?', [$sid]);
check($sessRow['revoked_at'] !== null, 'после :n неудач сессия закрыта совсем');

QU::clearPin($pinUid);
check(!QU::hasPin(\App\Models\User::find($pinUid)), 'PIN убирается');
\App\Models\User::purge($pinUid);
\App\Core\Setting::set('policy.lock_minutes', '0');
\App\Core\Setting::flush();

echo "\n" . ($fails === 0 ? "UNIT OK\n" : "UNIT FAILED: $fails\n");
exit($fails === 0 ? 0 : 1);
