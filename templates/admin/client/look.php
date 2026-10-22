<?php /**
 * «Экран входа» портала (v4.71): как выглядит вход, когда человек пришёл отсюда.
 *
 * $c, $mode, $portalTheme (присланное манифестом), $customTheme (своё),
 * $siteTheme (оформление установки), $methods (null — как на всём сайте),
 * $canWrite.
 *
 * Оформление бывает трёх видов, и выбор между ними — первое, что видно:
 * администратор может оставить то, что прислал портал, задать своё или
 * вернуть экран установки. Своё оформление не стирается при переключении —
 * к нему можно вернуться.
 */
use App\Core\Appearance;
use App\Core\Bridge;

$fromPortal = $portalTheme !== [];
/* Что прислал портал — словами, а не ключами манифеста. */
$sent = [];
foreach (['accent' => t('акцент'), 'background' => t('палитра светлой темы'), 'background_dark' => t('палитра тёмной темы'),
          'radius' => t('скругление'), 'font' => t('шрифт'), 'font_display' => t('шрифт заголовков'),
          'layout' => t('раскладка'), 'headline' => t('цитата')] as $k => $word) {
    if (isset($portalTheme[$k])) $sent[] = $word;
}
$adopt = $portalTheme + ['tagline' => (string)($c['brand_tagline'] ?? '')];
?>
<?php ob_start(); ?>
  <div class="bento-card look-card">
    <div class="bc-title"><?= esc(t('Откуда оформление')) ?></div>
    <?= field_choices('look_mode', '', [
          'portal' => ['title' => t('Как присылает портал'),
              'hint' => $fromPortal ? t('Тема из манифеста портала') : t('Портал тему не присылал — экран как у сайта')],
          'custom' => ['title' => t('Своё'), 'hint' => t('Цвета, шрифты и панель — здесь')],
          'off' => ['title' => t('Как у сайта'), 'hint' => t('Без оформления портала')],
        ], $mode, ['cols' => 3,
          /* v4.82: оформление портала ложится поверх оформления сайта. */
          'hint' => t('Как тема портала сочетается с «Оформлением сайта», решает выбор «Вход в порталы» там; «Своё» — как настроено здесь, а чего здесь не задано — оттуда.')]) ?>
    <div data-look-when="portal"<?= $mode === 'portal' ? '' : ' hidden' ?>>
      <?php if ($fromPortal): ?>
      <p class="hint mb-8"><?= esc(t('Портал прислал оформление в манифесте:')) ?>
        <?= esc(implode(', ', $sent)) ?>.
        <?= $c['manifest_fetched_at'] ? esc(t('Последний раз — :at.', ['at' => fmt_dt((string)$c['manifest_fetched_at'])])) : '' ?></p>
      <?php /* «Взять за основу» переносит присланное в своё оформление, и дальше
               его можно править здесь. Манифест при этом больше не решает. */ ?>
      <button class="btn btn-secondary" type="button" data-look-adopt="<?= esc((string)json_encode($adopt, JSON_UNESCAPED_UNICODE)) ?>">
        <?= icon('edit', 16) ?> <?= esc(t('Взять за основу и настроить')) ?></button>
      <?php else: ?>
      <p class="hint mb-0"><?= esc(t('Портал может прислать тему сам — в блоке branding своего манифеста (integration.md, §«Манифест»). Или задайте её здесь: «Своё».')) ?></p>
      <?php endif; ?>
    </div>
    <div data-look-when="custom"<?= $mode === 'custom' ? '' : ' hidden' ?>>
      <?php /* Сбросить руками девять цветов, три выбора и цитату — долго, а
               «начать заново» нужно при каждой смене замысла. Сохранённое
               не трогается, пока не нажата «Сохранить». */ ?>
      <button class="btn btn-ghost btn-s" type="button" data-look-reset><?= icon('refresh', 14) ?> <?= esc(t('Начать с чистого листа')) ?></button>
    </div>
    <div data-look-when="off"<?= $mode === 'off' ? '' : ' hidden' ?>>
      <p class="hint mb-0"><?= esc(t('Вход в этот сервис выглядит как вход в саму установку.')) ?>
        <a href="/admin/appearance#look"><?= esc(t('Оформление сайта →')) ?></a></p>
    </div>
  </div>

  <?php if (array_key_exists('login_display', $c)): ?>
  <?php /* Как портал открывает вход и что человек видит после (v4.72). Обе вещи
           — выбор владельца портала, а не обязаловка: «как на портале» и
           выключенный «Вы вошли» оставляют всё как было. */ ?>
  <div class="bento-card look-card">
    <div class="bc-title"><?= esc(t('Как открывается вход')) ?></div>
    <?= field_choices('login_display', '', [
          'auto' => ['title' => t('Как решил портал'), 'hint' => t('Как задано в коде портала')],
          'popup' => ['title' => t('Окном поверх сайта'), 'hint' => t('Форма входа во всплывающем окне прямо на странице портала')],
          'window' => ['title' => t('Отдельным окном браузера'), 'hint' => t('Маленькое окно браузера, страница портала затемняется')],
          'page' => ['title' => t('Всей страницей'), 'hint' => t('Переход на страницу входа и обратно')],
        ], (string)($c['login_display'] ?? '') ?: 'auto', ['cols' => 2,
          'hint' => t('Окна умеют порталы, подключённые скриптом sso.js из набора. Вход через сервисы и ссылкой из письма из окна на странице продолжается отдельным окном. На телефоне вход всегда открывается страницей.')]) ?>
    <?= field_toggle('login_welcome', t('Показывать «Вы вошли»'), (int)($c['login_welcome'] ?? 0) === 1,
          t('После входа — короткий экран со знаком сервиса и именем человека, через полторы секунды — возврат в сервис. Повторный заход уже вошедшего проходит без него.')) ?>
    <p class="hint mt-8 mb-0"><a href="/admin/clients/<?= (int)$c['id'] ?>/look/preview?screen=welcome" target="_blank" rel="noopener"><?= esc(t('Посмотреть «Вы вошли» →')) ?></a></p>
  </div>
  <?php endif; ?>
<?php $before = (string)ob_get_clean(); ?>

<?php ob_start(); ?>
  <div class="bento-card look-card" data-look-custom>
    <div class="bc-title"><?= esc(t('Над формой')) ?></div>
    <?= field_text('look[tagline]', t('Строка под именем сервиса'), (string)($customTheme['tagline'] ?? ''), [
          'id' => 'look-tagline', 'max' => 120,
          'placeholder' => (string)($c['brand_tagline'] ?? '') ?: t('Чем занят сервис — в одной строке'),
          'hint' => t('Стоит под «Вы входите в :name». Знак сервиса и знак SSO над формой остаются всегда.', ['name' => $c['name']])]) ?>
    <p class="hint mb-16"><?= esc(t('Знак сервиса над формой — его иконка из реквизитов.')) ?>
      <a href="/admin/clients/<?= (int)$c['id'] ?>/req"><?= esc(t('Реквизиты →')) ?></a></p>
  </div>

  <div class="bento-card look-card">
    <div class="bc-title"><?= esc(t('Способы входа')) ?></div>
    <?php
    $labels = [
        'password' => t('Логин и пароль'), 'magic' => t('Ссылка из письма'),
        'passkey' => t('Ключ устройства (passkey)'), 'social' => t('Вход через сервисы'),
    ];
    $options = [];
    foreach (Appearance::METHODS as $m) {
        $options[$m] = [$labels[$m], Appearance::method($m) ? '' : t('выключен на всём сайте')];
    }
    ?>
    <?= field_choices('methods_mode', '', [
          'all' => ['title' => t('Как на всём сайте'), 'hint' => t('Что включено в «Оформлении сайта»')],
          'some' => ['title' => t('Только выбранные'), 'hint' => t('Например, только пароль и passkey')],
        ], $methods === null ? 'all' : 'some', ['cols' => 2]) ?>
    <div data-look-methods<?= $methods === null ? ' hidden' : '' ?>>
      <?= field_checks('methods', '', $options, $methods ?? Appearance::METHODS, ['layout' => 'grid-2',
            'hint' => t('Выключенное на всём сайте здесь не включится. Если от выбора ничего не останется, экран покажет общий состав — запереть вход нельзя.')]) ?>
    </div>
  </div>

  <?php if ($customTheme !== []): ?>
  <details class="bento-card disclosure look-card" data-look-custom>
    <summary><span class="bc-title mb-0"><?= esc(t('Для разработчика портала')) ?></span>
      <span class="hint"><?= esc(t('то же оформление блоком манифеста')) ?></span></summary>
    <p class="hint mt-8"><?= esc(t('Портал может держать это у себя в /.well-known/sso-portal.json — тогда оформление переедет вместе с ним. Обложка в манифест не входит: картинку SSO загружает только отсюда.')) ?></p>
    <?= field_copy('look-manifest', '', App\Core\LoginLook::manifestJson($customTheme), ['class' => 'mb-0', 'rows' => 8]) ?>
  </details>
  <?php endif; ?>
<?php $after = (string)ob_get_clean(); ?>

<?php ob_start(); ?>
    <?php /* Обложка над цитатой — картинки, видео или YouTube (v4.81), той же частью, что у установки. */ ?>
    <?= App\Core\View::fetch('admin/_cover_field', ['clientId' => (int)$c['id']]) ?>
<?php $paneExtra = (string)ob_get_clean(); ?>

<form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/look" enctype="multipart/form-data" data-look-form>
  <?= csrf_field() ?>
  <fieldset class="look-fieldset"<?= $canWrite ? '' : ' disabled' ?>>
  <?= App\Core\View::fetch('admin/_look_editor', [
        'scope' => 'portal', 'theme' => $customTheme,
        'previewUrl' => '/admin/clients/' . (int)$c['id'] . '/look/preview',
        'before' => $before, 'after' => $after, 'paneExtra' => $paneExtra,
      ]) ?>
  </fieldset>
  <?php if ($canWrite): ?><?= form_actions() ?><?php endif; ?>
</form>
