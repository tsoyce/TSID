<?php
/**
 * Сборка deploy-архива: php build_dist.php → dist/sso-vX.Y.Z.zip
 * В архив попадает только прод: без .git, тестов, dev-конфига, ключей и БД.
 */

declare(strict_types=1);

$root = __DIR__;
$version = '2.0.0';
if (preg_match("~SSO_VERSION',\s*'([^']+)'~", (string)file_get_contents($root . '/app/bootstrap.php'), $m)) {
    $version = $m[1];
}
$distDir = $root . '/dist';
if (!is_dir($distDir)) mkdir($distDir, 0777, true);
$zipPath = $distDir . '/sso-v' . $version . '.zip';
@unlink($zipPath);

/* Что кладём: каталоги целиком + отдельные файлы корня */
$dirs = ['app', 'public', 'templates', 'migrations', 'install', 'lib'];
$files = ['cron.php', 'migrate.php', 'system.php',
    /* Заглушка в корне: объясняет голое «Not Found», когда не работает
       переписывание. Выполняется только в этом случае и ничего не ломает. */
    'index.php', 'README.md', 'LICENSE', 'CHANGELOG.md',
    'ARCHITECTURE.md', 'ROADMAP.md', 'PORTAL_PROTOCOL.md',
    /* SCOPES.md обязателен: на него ссылаются и ROADMAP, и ACCESS_MODEL,
       которые в архив едут. Документ, ссылающийся в пустоту, хуже, чем его
       отсутствие — читатель идёт искать несуществующий файл. */
    'SYSTEM_LIFECYCLE.md', 'ACCESS_MODEL.md', 'SCOPES.md', 'ROLES.md', 'AUDIT.md', '.htaccess'];
/* Пустые каталоги, которые должны существовать на сервере */
$emptyDirs = ['config', 'storage', 'storage/keys', 'storage/logs', 'storage/avatars', 'storage/cache'];
/* Никогда не кладём (защита от секретов даже при случайном попадании в $dirs) */
$forbidden = '~(^|/)(config/config\.php|installed\.lock|\.git|\.claude|dist/|tests/|node_modules)|\.sqlite$|\.pem$|\.eml$|\.log$~';

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Не удалось создать $zipPath\n");
    exit(1);
}

$count = 0;
/* Опись копится ЗДЕСЬ же, при укладке. Читать её потом из готового архива
   нельзя: пока $zip не закрыт, файла на диске ещё нет, а если от прошлой сборки
   он остался — опись опишет ЧУЖУЮ сборку и разойдётся с содержимым молча. */
$sizes = [];
$add = function (string $abs, string $rel) use ($zip, $forbidden, &$count, &$sizes): void {
    $rel = str_replace('\\', '/', $rel);
    if (preg_match($forbidden, $rel)) {
        echo "  ! пропущен (защита): $rel\n";
        return;
    }
    $zip->addFile($abs, $rel);
    /* Права — в самом архиве. Сборка идёт на Windows, и без этой строки каждый
       файл уезжал с 0666, а каталог с 0777: rsync при выкладке честно переносил
       их на сервер, и код SSO становился доступен на запись любому процессу
       машины. На сервере с тремя десятками сайтов это значит: взломали соседний
       сайт — переписали вход. Замерено на боевой: 359 файлов из 359. */
    $zip->setExternalAttributesName($rel, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    /* storage/ в опись не идёт: он живой — журналы, аватары, кэш. */
    if (!str_starts_with($rel, 'storage/')) $sizes[$rel] = (int)filesize($abs);
    $count++;
};

foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $add($file->getPathname(), substr($file->getPathname(), strlen($root) + 1));
        }
    }
}
foreach ($files as $f) {
    if (is_file($root . '/' . $f)) $add($root . '/' . $f, $f);
}
foreach ($emptyDirs as $d) {
    $zip->addEmptyDir($d);
    $zip->setExternalAttributesName($d . '/', ZipArchive::OPSYS_UNIX, 040755 << 16);
}
$zip->addFromString('storage/.gitkeep', '');
$zip->setExternalAttributesName('storage/.gitkeep', ZipArchive::OPSYS_UNIX, 0100644 << 16);

/**
 * Опись поставки: что и какого размера должно лежать на сервере.
 *
 * Заливка по FTP обрывается тихо: часть файлов доехала, часть нет, приложение
 * при этом выглядит установленным. На боевой так пропал один класс соцвхода —
 * и весь экран входа отдавал 500, потому что список провайдеров собирался
 * безусловно. Падение мы починили, но узнать «а всё ли доехало» было по-прежнему
 * нечем: опись затем и нужна.
 *
 * Размер, а не хэш содержимого: хэш ловил бы ещё и правки на месте, но
 * правки на месте — законный сценарий (кто-то поменял шаблон под себя), а
 * недоехавший файл — нет. Размер отличает одно от другого и не поднимает
 * ложную тревогу у тех, кто правил у себя.
 */
ksort($sizes);
$manifest = ['version' => $version, 'built_at' => gmdate('c'), 'files' => $sizes];
$zip->addFromString('dist-manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$zip->setExternalAttributesName('dist-manifest.json', ZipArchive::OPSYS_UNIX, 0100644 << 16);
echo 'В описи: ' . count($manifest['files']) . " файлов\n";

$zip->close();

echo "Готово: $zipPath\n";
echo 'Файлов: ' . $count . ', размер: ' . round(filesize($zipPath) / 1024) . " КБ\n";

/* Самопроверка: секретов внутри быть не должно */
$zip = new ZipArchive();
$zip->open($zipPath);

/* Самопроверка: права. Запись для группы или остальных — дефект поставки, а не
   мелочь: такие права без изменений доезжают до сервера. */
$loose = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $zip->getExternalAttributesIndex($i, $os, $attr);
    if ((($attr >> 16) & 0022) !== 0) $loose[] = (string)$zip->getNameIndex($i);
}
if ($loose !== []) {
    fwrite(STDERR, 'Самопроверка: права шире 644/755 у ' . count($loose) . ' записей, например ' . $loose[0] . "\n");
    exit(1);
}
echo "Самопроверка: права в архиве 644/755 ✓\n";
$bad = [];
$inZip = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $st = $zip->statIndex($i);
    $name = (string)$st['name'];
    $inZip[$name] = (int)$st['size'];
    if (preg_match('~config/config\.php|installed\.lock|\.sqlite|\.pem|storage/keys/k|dev-test~', $name)) {
        $bad[] = $name;
    }
}
$zip->close();
if ($bad !== []) {
    fwrite(STDERR, "ОПАСНО — в архиве секреты: " . implode(', ', $bad) . "\n");
    exit(1);
}
echo "Самопроверка: секретов в архиве нет ✓\n";

/* Самопроверка описи: она обязана описывать ИМЕННО этот архив.
   Опись, разошедшаяся с содержимым, хуже её отсутствия: страница «Поставка»
   будет уверенно называть недоехавшим то, что доехало, — и наоборот. Раньше
   опись читалась из архива до его закрытия и при остатке от прошлой сборки
   молча описывала ЧУЖОЙ архив; теперь это не проходит дальше сборщика. */
$mism = [];
foreach ($manifest['files'] as $name => $size) {
    if (!isset($inZip[$name])) { $mism[] = "нет в архиве: $name"; continue; }
    if ($inZip[$name] !== $size) $mism[] = "размер разошёлся: $name";
}
foreach ($inZip as $name => $size) {
    if (str_ends_with($name, '/') || str_starts_with($name, 'storage/')) continue;
    if ($name === 'dist-manifest.json') continue;
    if (!isset($manifest['files'][$name])) $mism[] = "нет в описи: $name";
}
if ($mism !== []) {
    fwrite(STDERR, "ОПИСЬ ЛЖЁТ (" . count($mism) . "):\n  "
        . implode("\n  ", array_slice($mism, 0, 20)) . "\n");
    exit(1);
}
echo 'Самопроверка: опись сходится с архивом ✓ (' . count($manifest['files']) . ")\n";
