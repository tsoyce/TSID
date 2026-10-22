<?php /**
 * Подключение сервиса: ссылка установки, файлы набора, ключи.
 *
 * Всё, что нужно той стороне, собрано в одном разделе. Раньше это было
 * растащено между шапкой карточки (реквизиты, видимые только сразу после
 * создания) и отдельной вкладкой — и половина находилась случайно.
 */
use App\Controllers\Admin\KitBuilder;
?>
<?php if ($fresh): ?>
<div class="bento-card mb-16 bordered-ink" id="credentials">
  <div class="bc-title"><?= esc(t('Реквизиты — показываются один раз')) ?></div>
  <?php if ($fresh['secret']): ?>
  <div class="secret-plate mb-16"><?= esc(t('Сохраните сейчас: client_secret показывается ОДИН раз, в базе остаётся только хэш.')) ?></div>
  <?php endif; ?>
  <div class="grid-2">
    <?= field_copy('cid', 'client_id', $c['client_id']) ?>
    <?php if ($fresh['secret']): ?>
    <?= field_copy('csec', 'client_secret', $fresh['secret']) ?>
    <?php endif; ?>
  </div>
  <?php if (!empty($fresh['install'])): ?>
  <?php /* Ссылка установки стоит первой: это короткий путь, а всё, что ниже —
           длинный. Человек, дочитавший до «впишите секрет», уже пошёл длинным. */ ?>
  <?= field_copy('install', t('Ссылка установки — откройте её на портале'), $fresh['install'], [
        'note' => t('(одноразовая, :n минут)', ['n' => (int)(App\Core\PortalInstall::TTL / 60)]),
        'hint' => t('Залейте каталог sso/ из набора на сайт и откройте эту ссылку. Портал сам заберёт реквизиты, ключ переноса и адреса — вписывать вручную будет нечего.')]) ?>
  <?php endif; ?>
  <?php if ($fresh['setup']): ?>
  <?= field_copy('setup', t('Setup-ссылка для первого админа портала'),
        base_url('/setup/' . $fresh['setup']), ['note' => t('(одноразовая, 7 дней)')]) ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php /* Связь с порталом (v4.68 — переехала сюда из «Ролей»): спросить портал о
         себе — ролях, оформлении, выгрузке людей — и увидеть, что из этого
         принято. Это разговор с порталом целиком, а не про роли. */ ?>
<div class="bento-card mb-16" id="portal-link">
  <div class="bc-title"><?= esc(t('Связь с порталом')) ?></div>
  <?php /* «Спросить у портала»: чтобы администратор не вбивал роли руками.
           Ответ только запоминается — карту он правит сам, потому что она
           управляет доступами. */ ?>
  <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/manifest">
    <?= csrf_field() ?>
    <button class="btn btn-secondary" type="submit"><?= icon('refresh', 16) ?> <?= esc(t('Спросить у портала')) ?></button>
    <p class="hint mt-8"><?= esc(t('SSO запросит :url — портал сам расскажет о своих ролях, оформлении и о том, умеет ли отдавать своих людей.', ['url' => '/.well-known/sso-portal.json'])) ?></p>
  </form>
  <?php $mErr = App\Core\PortalManifest::lastError((int)$c['id']); ?>
  <?php if ($mErr !== null): ?>
  <?php /* Причина — рядом с кнопкой, которой проверяют снова. «Не отвечает» без
           причины отправляло администратора гадать, а ответ обычно один из
           трёх: nginx отдаёт путь с диска, .htaccess режет точку, модуля нет. */ ?>
  <p class="hint mt-8" role="status"><?= esc(t('Последняя попытка — :at: :why', ['at' => fmt_dt((string)$mErr['at']), 'why' => (string)$mErr['why']])) ?></p>
  <p class="hint"><?= esc(t('Следующая попытка — сама, не позже чем через :h ч. Починили на стороне портала — нажмите «Спросить у портала», ждать не нужно.', ['h' => max(1, (int)round(App\Core\PortalManifest::retryAfter((int)$c['id']) / 3600))])) ?></p>
  <?php endif; ?>
  <?php /* Что из присланного оформления применяется и почему нет — в разделе
           «Экран входа» (v4.71), рядом с предпросмотром. Здесь только дорога
           туда: тема, не прошедшая по контрасту, молча оставила бы родной вид. */ ?>
  <?php if (!empty($c['brand_theme'])): ?>
  <p class="hint mt-8"><?= esc(t('Портал прислал оформление экрана входа.')) ?>
    <a href="/admin/clients/<?= (int)$c['id'] ?>/look"><?= esc(t('Экран входа →')) ?></a></p>
  <?php endif; ?>
</div>

<div class="bento-card">
  <div class="bc-title"><?= esc(t('Файлы для портала')) ?></div>
  <p class="muted"><?= esc(t('Спека для нейросети и референс-код: спека говорит «что», набор показывает «как».')) ?></p>
  <div class="row">
    <a data-download class="btn btn-primary" href="/admin/clients/<?= (int)$c['id'] ?>/integration.md"><?= esc(t('Скачать integration.md')) ?></a>
    <a data-download class="btn btn-primary" href="/admin/clients/<?= (int)$c['id'] ?>/kit.zip"><?= esc(t('Скачать PHP-kit (.zip)')) ?></a>
    <button class="btn btn-secondary" type="button" data-copy="prompt-text2"><?= esc(t('Скопировать промпт для ИИ')) ?></button>
  </div>
  <textarea id="prompt-text2" hidden><?= esc(KitBuilder::prompt($c)) ?></textarea>
  <p class="hint mt-8"><?= esc(t('client_secret в файлы не включается — он доступен только в момент создания или перевыпуска.')) ?></p>
  <?php /* Экран настроек у портала обязателен (v4.63): туда вводят перевыпущенный
           секрет и ключ переноса, там же проверка связи. Говорим об этом там, где
           берут набор, — иначе о нём узнают, когда секрет уже сменили. */ ?>
  <p class="hint"><?= esc(t('В наборе — экран настроек портала, /sso/admin.php: ввод секрета клиента и ключа переноса и проверка связи. Он обязателен и для портала, собранного по integration.md без набора (§12).')) ?></p>
</div>

<?php if ($isGlobalAdmin): ?>
<div class="bento-card mt-16">
  <div class="bc-title"><?= esc(t('Ключи и ссылки')) ?></div>
  <div class="row">
    <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/action">
      <?= csrf_field() ?><input type="hidden" name="action" value="install_link">
      <button class="btn btn-primary" type="submit"><?= icon('key', 16) ?> <?= esc(t('Ссылка установки')) ?></button>
    </form>
    <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/action"
          data-confirm="<?= esc(t('Перевыпустить секрет? Старый будет работать ещё 24 часа.')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="rotate_secret">
      <button class="btn btn-secondary" type="submit"><?= esc(t('Перевыпустить секрет')) ?></button>
    </form>
    <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/action">
      <?= csrf_field() ?><input type="hidden" name="action" value="setup_link">
      <button class="btn btn-secondary" type="submit"><?= esc(t('Setup-ссылка для админа портала')) ?></button>
    </form>
    <?php if ($c['client_secret_old_expires_at'] && strtotime($c['client_secret_old_expires_at'] . ' UTC') > time()): ?>
    <span class="badge badge-warn"><?= esc(t('старый секрет активен до :when', ['when' => fmt_dt($c['client_secret_old_expires_at'], 'd.m H:i')])) ?></span>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

