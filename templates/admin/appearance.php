<?php /**
 * Оформление сайта (§5.4): главная и экран входа — две страницы, которые
 * видит не вошедший человек. $portals, $social, $siteTheme.
 */
use App\Core\Appearance;
use App\Core\Setting;
?>
<?php /* Заголовок и пояснение рисует оболочка; ссылка на брендинг — кнопкой
         в шапке (v4.80): второе пояснение под первым повторяло его. */ ?>

<?php /* Одна форма на весь экран и одна полоса сохранения: было две — по
         одной в каждой карточке, — и человек, поправивший обе, сохранял
         только ту, у которой нажал. Части остались раздельными на сервере,
         но снаружи это один экран с одной кнопкой. */ ?>
<form method="post" action="/admin/appearance/save" enctype="multipart/form-data">
  <?= csrf_field() ?>
<div class="bento">
  <div class="bento-card col-12" id="home">
    <div class="bc-title"><?= esc(t('Главная')) ?></div>
    <?php /* Перечень внутренних сервисов — сведения о том, как устроена
             организация. Отдавать их любому, кто знает адрес, — решение, а
             не настройка, забытая во включённом положении. */ ?>
    <?php /* Тумблер, а не галочка: это состояние настройки. Галочки ниже —
             выбор из списка, и в этом разница. */ ?>
    <?= field_toggle('showcase', t('Показывать витрину проектов на главной'), Appearance::showcase(),
          t('Витрина отвечает «куда я попал» тому, кто набрал адрес случайно или пришёл по ссылке из письма. Без отмеченных порталов её не будет в любом случае: пустая витрина хуже, чем сразу форма входа.')) ?>

    <?php /* Снимок главной портала (v4.77): картинку превью ссылок (og:image)
             отдают не все, и без неё витрина стояла в цветных карточках.
             Сервис назван прямо — ему уходит адрес главной портала. */ ?>
    <?= field_toggle('cover_shots', t('Снимать главную портала для витрины'), App\Core\PortalCover::shotsOn(),
          t('Если портал не отдаёт картинку превью ссылок (og:image), обложкой станет снимок его главной. Снимок делает сервис Microlink (microlink.io): ему уходит публичный адрес главной портала, больше ничего. Раз в сутки на портал; своя картинка в карточке портала главнее.')) ?>

    <div class="bc-title"><?= esc(t('Что показывать')) ?></div>
    <?php if ($portals === []): ?>
      <?= empty_state('portal', t('Порталов пока нет — показывать нечего.'),
          '<a class="btn btn-secondary" href="/admin/clients">' . esc(t('К порталам →')) . '</a>') ?>
    <?php else: ?>
    <?php
    $portalOptions = $portalChecked = [];
    foreach ($portals as $p) {
        $portalOptions[(int)$p['id']] = [
            $p['name'] . (empty($p['is_active']) ? ' — ' . t('выключен') : ''),
            host_label((string)$p['base_url']),
        ];
        if (!empty($p['show_on_home'])) $portalChecked[] = (int)$p['id'];
    }
    ?>
    <?= field_checks('portal', '', $portalOptions, $portalChecked, [
          'layout' => 'grid-2',
          'hint' => t('Выключенный портал в витрину не попадает, даже если отмечен.')]) ?>
    <?php endif; ?>
  </div>
</div>

<?php /* Экран входа установки (v4.71) — тем же редактором, что у порталов:
         палитра обеих тем, форма, шрифты, раскладка и настоящий экран входа
         рядом. Порталы без своего оформления выглядят так же. */ ?>
<h2 class="section-title mt-24" id="look"><?= esc(t('Экран входа')) ?></h2>
<p class="muted mb-16"><?= esc(t('Так выглядит вход в саму установку — и в любой портал, у которого нет своего оформления.')) ?></p>
<?php /* На весь сайт (v4.77): палитра даёт все токены интерфейса с гарантией
         контраста, и приложить её можно к любой странице. */ ?>
<div class="bento-card mb-16">
  <?= field_toggle('look_everywhere', t('Это оформление — на весь сайт'), App\Core\Appearance::lookEverywhere(),
        t('Цвета, шрифты и скругления — и на главной, и в кабинете, и в панели. Выключено — только на экранах входа; главная — тоже экран входа, она в оформлении всегда.')) ?>
  <?php /* Чей дизайн главнее на входе в порталы (v4.85): портал присылает тему
           манифестом, и раньше она перекрывала почти всё отсюда. */ ?>
  <?= field_choices('portal_look', t('Вход в порталы'), [
        'site' => ['title' => t('Дизайн сайта главнее'), 'hint' => t('Шрифты, форма, карточки, узор, фон и раскладка — отсюда; цвета — портала, чтобы вход в него узнавался')],
        'portal' => ['title' => t('Портал главнее'), 'hint' => t('Что портал прислал в манифесте — его; чего не прислал — отсюда')],
        'only' => ['title' => t('Только дизайн сайта'), 'hint' => t('Все порталы выглядят как вход в установку; их темы не применяются')],
      ], App\Core\Appearance::portalLook(), ['cols' => 3,
        'hint' => t('Для тем, которые порталы присылают сами. Портал, которому в карточке задано «Своё», выглядит как настроено там.')]) ?>
</div>

<?php ob_start(); ?>
  <div class="bento-card look-card">
    <div class="bc-title"><?= esc(t('Способы входа на экране')) ?></div>
    <?php /* Состав формы не настраивался вовсе: показывалось всё, что
             система вообще умеет. Установке, где вход только логином и
             паролём, три лишних способа на экране мешают, а не помогают. */ ?>
    <?php
    $methods = [
      'password' => [t('Логин и пароль'), t('Основная форма. Нужна тем, кого перенесли из сервиса без почты.')],
      'magic'    => [t('Ссылка из письма'), t('Строка с адресом под формой. Без настроенного SMTP работать не будет.')],
      'passkey'  => [t('Ключ устройства (passkey)'), t('Отпечаток или лицо средствами системы.')],
      'social'   => [t('Вход через сервисы'), $social === []
          ? t('Ни один провайдер не настроен — на экране ничего не появится.')
          : t('Настроено провайдеров: :n.', ['n' => count($social)])],
    ];
    foreach ($methods as $key => [$label, $why]):
    ?>
    <?= field_toggle('method_' . $key, $label, Appearance::method($key), $why) ?>
    <?php endforeach; ?>
    <?php /* v4.86: порядок — как у Google, Apple и X, если владелец хочет. */ ?>
    <?= field_toggle('social_first', t('Сервисы — первыми'), Appearance::socialFirst(),
          t('Кнопки «Продолжить с …» над формой, как у Google, Apple и X: в окне поверх сайта портала они тогда видны сразу. Выключено — под формой.')) ?>
    <p class="hint"><?= esc(t('Выключить все нельзя: хотя бы один способ обязан остаться, иначе войти не сможет никто, включая вас.')) ?></p>
  </div>
<?php $before = (string)ob_get_clean(); ?>

<?php ob_start(); ?>
    <?php /* Панель — единственное, что человек видит на чужом сайте, и видит
             на каждом сайте группы. По умолчанию там акцентная заставка со
             знаком: белая марка означает, что большинство ничего не
             загрузит. */ ?>
    <?php /* Обложка — картинки, видео или YouTube (v4.81), той же частью, что у порталов. */ ?>
    <?= App\Core\View::fetch('admin/_cover_field', ['clientId' => null]) ?>
    <?= field_text('media_title', t('Заголовок панели'), (string)setting('branding.media_title', ''), [
          'max' => 80, 'placeholder' => Setting::appName()]) ?>
    <?php /* Подпись стоит ПОД обложкой, а не поверх: поверх она читалась бы
             только на тёмных кадрах — белый заголовок на снегу или светлом
             офисе давал бы около 2:1. */ ?>
    <?= field_text('media_text', t('Строка под заголовком'), (string)setting('branding.media_text', ''), [
          'max' => 160, 'placeholder' => t('Один аккаунт на все сервисы.'),
          'hint' => t('Подпись рисуется под обложкой — так она читается на любом кадре.')]) ?>
<?php $paneExtra = (string)ob_get_clean(); ?>

<?= App\Core\View::fetch('admin/_look_editor', [
      'scope' => 'site', 'theme' => $siteTheme,
      'previewUrl' => '/admin/appearance/preview',
      'before' => $before, 'paneExtra' => $paneExtra,
    ]) ?>

<?php /* Фон страниц (v4.84): тот же набор, что у обложки, — картинки, видео,
         YouTube, — только за всей страницей, под вуалью цвета фона. */
$bd = App\Core\Backdrop::settings(); ?>
<h2 class="section-title mt-24" id="backdrop"><?= esc(t('Фон страниц')) ?></h2>
<p class="muted mb-16"><?= esc(t('Картинки или видео за формами входа, кабинетом и админкой. Текст стоит на вуали цвета фона, карточки остаются карточками; со «стеклом» в форме сквозь них размыто виден фон.')) ?></p>
<div class="bento">
  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Где показывать')) ?></div>
    <?= field_checks('backdrop_areas', '', [
          'auth' => [t('Вход и главная'), ''],
          'cabinet' => [t('Кабинет'), ''],
          'admin' => [t('Админка'), ''],
        ], $bd['areas'], ['hint' => t('Ничего не отмечено — фона нет нигде. В окне входа на странице портала фона нет всегда: вокруг свой сайт.')]) ?>
    <?= field_choices('backdrop_veil', t('Приглушение'), [
          'strong' => ['title' => t('Сильное')],
          'medium' => ['title' => t('Среднее')],
          'light' => ['title' => t('Лёгкое')],
        ], $bd['veil'], ['class' => 'choices-seg',
          'hint' => t('Сильное — фон едва угадывается, текст читается как на ровном. Чем легче, тем ярче фон и тем труднее читаются подписи вне карточек.')]) ?>
    <?= field_choices('backdrop_blur', t('Размытие'), [
          'none' => ['title' => t('Без размытия')],
          'soft' => ['title' => t('Лёгкое')],
          'strong' => ['title' => t('Сильное')],
        ], $bd['blur'], ['class' => 'choices-seg']) ?>
    <input type="hidden" name="backdrop_parallax" value="0">
    <?= field_toggle('backdrop_parallax', t('Живой фон'), $bd['parallax'],
          t('Фон чуть смещается за курсором — как окно, в которое смотришь под другим углом. Только мышью; кто просил «без движения», увидит его неподвижным.')) ?>
  </div>
  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Что на фоне')) ?></div>
    <?= App\Core\View::fetch('admin/_cover_field', ['owner' => App\Core\CoverSet::BACKDROP, 'prefix' => 'bg',
          'label' => t('Фон'), 'sound' => false,
          'kindHint' => t('Видео и YouTube — только на широком экране и не при экономии трафика; на телефоне стоит первая картинка.')]) ?>
  </div>
</div>
<?= form_actions() ?>
</form>

<?php /* Шрифты из Google Fonts (v4.78): SSO скачивает файлы к себе один раз
         и отдаёт со своего домена — браузеры посетителей к Google не ходят.
         Установленные появляются в выборе шрифтов выше и у порталов. */
$googleFonts = App\Core\FontLibrary::installed();
$catalog = App\Core\FontLibrary::catalogCached(); ?>
<h2 class="section-title mt-24" id="fonts"><?= esc(t('Шрифты')) ?></h2>
<p class="muted mb-16"><?= esc(t('Любой шрифт Google Fonts с кириллицей. SSO скачивает его файлы к себе один раз и отдаёт со своего домена: браузеры посетителей к Google не ходят. Установленный появится в выборе шрифтов выше и в оформлении порталов.')) ?></p>
<?php if ($googleFonts !== []): ?>
<style><?= App\Core\FontLibrary::faces(array_map([App\Core\FontLibrary::class, 'key'], array_keys($googleFonts))) ?></style>
<?php endif; ?>
<div class="bento">
  <div class="bento-card col-12">
    <?php if ($googleFonts === []): ?>
      <p class="hint"><?= esc(t('Своих шрифтов пока нет — работают шрифты поставки: Golos Text, Inter, EB Garamond и шрифт устройства.')) ?></p>
    <?php else: ?>
    <ul class="font-list">
      <?php foreach ($googleFonts as $slug => $gf): $used = App\Core\FontLibrary::usedBy($slug); ?>
      <li class="font-row">
        <span class="font-row-sample ff-var" style="--ff:<?= esc(App\Core\Bridge::fontStack(App\Core\FontLibrary::key($slug))) ?>"><?= esc(t('Аа Вход')) ?> — <?= esc($gf['family']) ?></span>
        <span class="hint"><?= esc(t($gf['category'])) ?><?= $used !== [] ? ' · ' . esc(t('используется: :where', ['where' => implode(', ', $used)])) : '' ?></span>
        <form method="post" action="/admin/appearance/fonts">
          <?= csrf_field() ?><input type="hidden" name="remove" value="<?= esc($slug) ?>">
          <button class="btn btn-ghost btn-s" type="submit"<?= $used !== [] ? ' disabled title="' . esc(t('Сначала выберите в оформлении другой шрифт')) . '"' : '' ?>><?= esc(t('Убрать')) ?></button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php /* Поле и кнопка — одной строкой, подсказка под обоими (v4.80): раньше
             кнопку выравнивала подсказка под полем, и она стояла ниже. */ ?>
    <form method="post" action="/admin/appearance/fonts" class="font-add">
      <?= csrf_field() ?>
      <div class="field mb-0">
      <label for="f-family"><?= esc(t('Добавить шрифт из Google Fonts')) ?></label>
      <div class="font-add-row">
        <input class="input" id="f-family" name="family" type="text" placeholder="Manrope" list="google-fonts" required autocomplete="off">
        <button class="btn btn-primary" type="submit"><?= icon('download', 16) ?> <?= esc(t('Установить')) ?></button>
      </div>
      <p class="hint"><?= esc($catalog === []
          ? t('Каталог Google подгружается — обновите страницу через минуту или впишите точное название семейства.')
          : t('Каталог с кириллицей: :n семейств. Начните вводить название — появятся подсказки.', ['n' => count($catalog)])) ?></p>
      </div>
      <datalist id="google-fonts">
        <?php foreach ($catalog as $f): ?><option value="<?= esc($f['family']) ?>"><?= esc(t($f['category'])) ?></option><?php endforeach; ?>
      </datalist>
    </form>
  </div>
</div>
