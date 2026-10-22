<?php /**
 * Редактор оформления экрана входа (v4.71) — общий для портала и установки.
 *
 * Слева настройки, справа настоящий экран входа во фрейме: меняешь цвет —
 * видишь вход. Предпросмотр рисует тот же шаблон, что видят люди, с тем же
 * разбором параметров, поэтому показать то, чего не будет на живом экране,
 * он не может.
 *
 * $scope      portal | site
 * $theme      что сейчас в редакторе (своё оформление портала или установки)
 * $previewUrl адрес страницы предпросмотра
 * $before     HTML над настройками (у портала — выбор источника)
 * $paneExtra  HTML в конце карточки «Панель» (у установки — её обложка и подпись)
 * $after      HTML под настройками (у портала — способы входа и манифест)
 */
use App\Core\Bridge;
use App\Core\FontLibrary;
use App\Core\Setting;

$v = static fn(string $k): string => isset($theme[$k]) ? (string)$theme[$k] : '';
$hasDark = false;
foreach (Bridge::COLORS as $name) if (isset($theme[$name . '_dark'])) $hasDark = true;
$siteAccent = (string)Setting::get('branding.accent', '') ?: '#1D4ED8';
$colorLabels = [
    'accent' => [t('Акцент'), t('кнопки и ссылки')],
    'background' => [t('Фон страницы'), ''],
    'surface' => [t('Карточка и поля'), ''],
    'text' => [t('Текст'), ''],
];
/* У портала пустое поле — цвет сайта (v4.82: оформление портала ложится
   поверх оформления сайта), и подсказка показывает именно его. */
$siteLook = $scope === 'portal' ? Bridge::siteLook() : [];
$colorField = static function (string $key, bool $dark) use ($v, $colorLabels, $siteAccent, $siteLook): string {
    $name = $key . ($dark ? '_dark' : '');
    [$label, $note] = $colorLabels[$key];
    $native = $siteLook[$name] ?? ($key === 'accent' ? ($dark ? '#60A5FA' : $siteAccent) : Bridge::NATIVE[$name]);
    $value = $v($name);
    return field_color('look[' . $name . ']', $label, $value, $value !== '' ? $value : $native, [
        'id' => 'look-' . $name, 'placeholder' => $native, 'note' => $note]);
};
$fontSample = static fn(string $key): string =>
    '<span class="font-sample ff-' . esc($key) . '">Аа Вход</span>';
$fontTitles = ['golos' => 'Golos Text', 'inter' => 'Inter', 'system' => t('Шрифт устройства'), 'garamond' => 'EB Garamond'];
/* Шрифты из Google, установленные у нас (v4.78): образец рисуется их же
   файлом — @font-face со своего домена. */
$googleFonts = FontLibrary::installed();
$googleSample = static fn(string $slug): string => font_sample(FontLibrary::key($slug));
?>
<?php if ($googleFonts !== []): ?><style><?= FontLibrary::faces(array_map([FontLibrary::class, 'key'], array_keys($googleFonts))) ?></style><?php endif; ?>
<div class="look-editor" data-look-editor data-preview="<?= esc($previewUrl) ?>">
<div class="look-controls">
  <?= $before ?? '' ?>

  <?php /* Схемы — первой карточкой (v4.80): это самый короткий путь к виду.
           Свои — наверху, дальше базовые (установка, чёрно-белая,
           бело-чёрная) и цветные. Плитка — коллаж: слева светлая тема,
           справа тёмная. */
        $mySchemes = App\Core\LookSchemes::all();
        $canScheme = App\Core\Auth::can('settings.write');
        $presetTile = static fn(string $key, string $name, array $pal): string => App\Core\View::fetch('admin/_look_scheme_tile', [
            'scheme' => ['id' => 'preset-' . $key, 'name' => t($name), 'theme' => $pal],
            'payload' => App\Core\LookSchemes::paletteValues($pal), 'canDrop' => false]); ?>
  <div class="bento-card look-card" data-look-custom>
    <div class="bc-title"><?= esc(t('Схемы')) ?></div>
    <div class="look-mine-box" data-look-mine-box>
      <div class="look-sub-title"><?= esc(t('Мои схемы')) ?></div>
      <div class="look-presets" data-look-mine-list<?= $mySchemes === [] ? ' hidden' : '' ?>>
        <?php foreach ($mySchemes as $scheme) echo App\Core\View::fetch('admin/_look_scheme_tile', ['scheme' => $scheme, 'canDrop' => $canScheme]); ?>
      </div>
      <p class="hint" data-look-mine-empty<?= $mySchemes !== [] ? ' hidden' : '' ?>><?= esc(t('Своих схем пока нет. Настройте цвета, шрифты и форму — и сохраните их одной плиткой: она появится и у порталов.')) ?></p>
      <?php if ($canScheme): ?>
      <div class="look-mine-save">
        <button class="btn btn-secondary btn-s" type="button" data-look-save-scheme><?= icon('plus', 15) ?> <?= esc(t('Сохранить как схему')) ?></button>
        <span class="hint" role="status" data-look-scheme-status></span>
      </div>
      <?php endif; ?>
    </div>
    <div class="look-sub-title"><?= esc(t('Базовые')) ?></div>
    <div class="look-presets" role="list" aria-label="<?= esc(t('Базовые схемы')) ?>">
      <?php foreach (Bridge::BASE_PRESETS as $pk => [$pname, $pal]) echo $presetTile($pk, $pname, $pal); ?>
    </div>
    <div class="look-sub-title"><?= esc(t('Цветные')) ?></div>
    <div class="look-presets" role="list" aria-label="<?= esc(t('Готовые схемы')) ?>">
      <?php foreach (Bridge::PRESETS as $pk => [$pname, $pal]) echo $presetTile($pk, $pname, $pal); ?>
    </div>
  </div>

  <div class="bento-card look-card" data-look-custom>
    <div class="bc-title"><?= esc(t('Цвета')) ?></div>
    <?php /* Сборка из одного цвета — главный путь: цвет бренда знают все, а
             фон, карточку и текст двух тем с гарантией контраста не подберёт
             никто. Дальше палитру можно поправить руками. */ ?>
    <div class="look-palette">
      <?= field_color('look_seed', t('Собрать палитру из цвета'), $v('accent'), $v('accent') !== '' ? $v('accent') : $siteAccent, [
            'id' => 'look-seed', 'placeholder' => $siteAccent,
            'hint' => t('Фон, карточка и текст обеих тем подберутся с запасом по контрасту. Поправить их можно ниже.')]) ?>
      <button class="btn btn-secondary" type="button" data-look-palette><?= icon('palette', 16) ?> <?= esc(t('Собрать')) ?></button>
    </div>

    <div class="look-schemes">
      <div class="look-scheme">
        <div class="look-scheme-title"><?= icon('sun', 16) ?> <?= esc(t('Светлая тема')) ?></div>
        <?php foreach (Bridge::COLORS as $key) echo $colorField($key, false); ?>
      </div>
      <div class="look-scheme">
        <div class="look-scheme-title"><?= icon('moon', 16) ?> <?= esc(t('Тёмная тема')) ?></div>
        <?php /* Тёмная — по выбору: без неё тёмная тема остаётся родной, а цвета
                 портала живут в светлой. Половина тёмной палитры не бывает. */ ?>
        <input type="hidden" name="look[dark]" value="0">
        <?= field_toggle('look[dark]', t('Своя тёмная тема'), $hasDark, t('Выключено — тёмная тема родная.'), ['id' => 'look-dark']) ?>
        <div data-look-dark<?= $hasDark ? '' : ' hidden' ?>>
          <?php foreach (Bridge::COLORS as $key) echo $colorField($key, true); ?>
        </div>
      </div>
    </div>
    <?php /* Пустые поля — «как у установки»: подсказка в поле показывает,
             какой цвет тогда будет. */ ?>
    <p class="hint mb-0"><?= esc(t('Пустое поле — цвет установки, он виден подсказкой. Палитра темы применяется целиком: фон, карточка и текст вместе.')) ?></p>
  </div>

  <?php
  /* Конструктор (v4.79–4.80): всё — выбором из списка, не CSS. Пустое — как у
     установки. Короткие варианты — рядом пилюль (choices-seg): в карточках
     по 52px подписи рвались посреди слова («Обычна/я»). */
  $opts = static function (array $titles, string $default, array $samples = []): array {
      $out = ['' => ['title' => $default] + (isset($samples['']) ? ['sample' => $samples['']] : [])];
      foreach ($titles as $k => $title) $out[(string)$k] = ['title' => $title] + (isset($samples[$k]) ? ['sample' => $samples[$k]] : []);
      return $out;
  };
  $seg = ['class' => 'choices-seg'];
  $weights = ['400' => t('Обычная'), '500' => t('Средняя'), '600' => t('Полужирная'), '700' => t('Жирная')];
  $uiFonts = ['' => ['title' => t('Как у сайта'), 'sample' => $fontSample('golos')]];
  foreach (Bridge::UI_FONTS as $f) $uiFonts[$f] = ['title' => $fontTitles[$f], 'sample' => $fontSample($f)];
  foreach ($googleFonts as $slug => $gf) {
      if (FontLibrary::forUi(FontLibrary::key($slug))) $uiFonts[FontLibrary::key($slug)] = ['title' => $gf['family'], 'sample' => $googleSample($slug)];
  }
  $displayFonts = ['' => ['title' => t('Как текст'), 'sample' => '<span class="font-sample">Аа Вход</span>']];
  foreach (array_keys(Bridge::FONTS) as $f) $displayFonts[$f] = ['title' => $fontTitles[$f], 'sample' => $fontSample($f)];
  foreach ($googleFonts as $slug => $gf) $displayFonts[FontLibrary::key($slug)] = ['title' => $gf['family'], 'sample' => $googleSample($slug)];
  ?>
  <div class="bento-card look-card" data-look-custom>
    <div class="bc-title"><?= esc(t('Шрифты')) ?></div>
    <?= field_choices('look[font]', t('Шрифт интерфейса'), $uiFonts, $v('font'), ['cols' => 3,
          'hint' => t('Подписи, поля, кнопки. Только рубленые: антиква в 13px не читается.')]) ?>
    <?= field_choices('look[font_display]', t('Шрифт заголовков и цитаты'), $displayFonts, $v('font_display'), ['cols' => 3]) ?>
    <?= field_choices('look[weight_heading]', t('Толщина заголовков'), $opts($weights, t('Как у сайта')), $v('weight_heading'), $seg) ?>
    <?= field_choices('look[weight_button]', t('Толщина кнопок и подписей'), $opts($weights, t('Как у сайта')), $v('weight_button'), $seg) ?>
    <p class="hint mb-0"><?= $scope === 'site'
        ? esc(t('Больше шрифтов — любой из Google Fonts с кириллицей: раздел «Шрифты» ниже на этой странице.'))
        : esc(t('Больше шрифтов ставится в оформлении сайта — и сразу появляется здесь.')) ?></p>
  </div>

  <div class="bento-card look-card" data-look-custom>
    <div class="bc-title"><?= esc(t('Форма')) ?></div>
    <?php
    $radii = [0, 4, 8, 12, 16];
    if ($v('radius') !== '' && !in_array((int)$v('radius'), $radii, true)) { $radii[] = (int)$v('radius'); sort($radii); }
    $radiusOptions = ['' => ['title' => t('Сайт'), 'sample' => '<span class="radius-sample r-8"></span>']];
    foreach ($radii as $r) {
        $radiusOptions[(string)$r] = ['title' => $r . ' px',
            'sample' => '<span class="radius-sample r-' . $r . '"></span>'];
    }
    $btnSample = static fn(string $kind): string => '<span class="btn-sample bs-' . $kind . '">' . esc(t('Войти')) . '</span>';
    ?>
    <?= field_choices('look[radius]', t('Скругление углов'), $radiusOptions, $v('radius'), ['class' => 'choices-compact']) ?>
    <?= field_choices('look[button]', t('Главная кнопка'), $opts(['fill' => t('Заливка'), 'soft' => t('Мягкая'), 'outline' => t('Контур')], t('Как у сайта'),
          ['' => $btnSample('fill'), 'fill' => $btnSample('fill'), 'soft' => $btnSample('soft'), 'outline' => $btnSample('outline')]), $v('button'), ['cols' => 4]) ?>
    <?= field_choices('look[field_border]', t('Рамка полей'), $opts(['1' => '1 px', '1.5' => '1,5 px', '2' => '2 px'], t('Как у сайта')), $v('field_border'), $seg) ?>
    <?= field_choices('look[control]', t('Высота полей и кнопок'), $opts(['compact' => t('Плотная'), 'normal' => t('Обычная'), 'large' => t('Крупная')], t('Как у сайта')), $v('control'), $seg) ?>
    <?= field_choices('look[shadows]', t('Тени'), $opts(['none' => t('Без теней'), 'soft' => t('Мягкие'), 'strong' => t('Заметные')], t('Как у сайта')), $v('shadows'), $seg) ?>
    <?= field_choices('look[card]', t('Карточки'), $opts(['flat' => t('Плоские'), 'glass' => t('Стекло')], t('С тенью')), $v('card'), $seg + [
          'hint' => t('Стекло — полупрозрачные карточки, сквозь которые размыто виден фон.')]) ?>
  </div>

  <div class="bento-card look-card" data-look-custom>
    <div class="bc-title"><?= esc(t('Фон и тема')) ?></div>
    <?= field_choices('look[theme_default]', t('Тема для новых посетителей'), $opts(['light' => t('Светлая'), 'dark' => t('Тёмная')], t('Как у устройства')), $v('theme_default'), $seg + [
          'hint' => t('Пока человек сам не переключил тему кнопкой внизу. Выбор человека главнее.')]) ?>
    <?php $patternSample = static fn(string $p): string => '<span class="pattern-sample ps-' . $p . '"></span>'; ?>
    <?= field_choices('look[pattern]', t('Узор фона'), $opts(['grid' => t('Сетка'), 'lines' => t('Линии')], t('Без узора'),
          ['' => $patternSample('none'), 'grid' => $patternSample('grid'), 'lines' => $patternSample('lines')]), $v('pattern'), ['cols' => 3]) ?>
    <input type="hidden" name="look[wash]" value="0">
    <?= field_toggle('look[wash]', t('Подкраска фона'), ($theme['wash'] ?? true) !== false,
          t('Лёгкие пятна акцентного цвета на фоне страницы. Выключено — фон ровный.'), ['id' => 'look-wash']) ?>
  </div>

  <div class="bento-card look-card" data-look-custom>
    <div class="bc-title"><?= esc(t('Панель рядом с формой')) ?></div>
    <?php
    $layoutSample = static fn(string $l): string => '<span class="layout-sample is-' . $l . '"><i></i><b></b></span>';
    ?>
    <?= field_choices('look[layout]', t('Раскладка'), [
          'left' => ['title' => t('Панель слева'), 'sample' => $layoutSample('left')],
          'right' => ['title' => t('Панель справа'), 'sample' => $layoutSample('right')],
          'none' => ['title' => t('Только форма'), 'sample' => $layoutSample('none')],
        ], $v('layout') !== '' ? $v('layout') : 'left', ['cols' => 3,
          'hint' => t('Панель видна на широком экране. На телефоне её нет в любом случае — там весь экран у формы.')]) ?>
    <?php if ($scope === 'portal'): ?>
    <?= field_textarea('look[headline]', t('Цитата на панели'), $v('headline'), [
          'id' => 'look-headline', 'rows' => 2, 'max' => 160,
          'placeholder' => t('Одна-две фразы голосом сервиса'),
          'hint' => t('Шрифтом заголовков. Без цитаты и обложки панель будет как у всей установки.')]) ?>
    <?= field_text('look[headline_note]', t('Подпись к цитате'), $v('headline_note'), [
          'id' => 'look-headline-note', 'max' => 60, 'placeholder' => '— resume builder, 2026']) ?>
    <?php endif; ?>
    <?= $paneExtra ?? '' ?>
  </div>

  <?= $after ?? '' ?>
</div>

<aside class="look-preview" aria-label="<?= esc(t('Предпросмотр экрана входа')) ?>">
  <div class="look-preview-bar">
    <?php /* Значками, а не словами: в узкой колонке две пары подписей
             переносились на вторую строку. Слово — в подсказке и для диктора. */ ?>
    <div class="segmented look-seg" role="radiogroup" aria-label="<?= esc(t('Тема')) ?>">
      <label title="<?= esc(t('Светлая')) ?>"><input type="radio" name="_scheme" value="light" checked data-look-scheme><span><?= icon('sun', 16) ?><span class="sr-only"><?= esc(t('Светлая')) ?></span></span></label>
      <label title="<?= esc(t('Тёмная')) ?>"><input type="radio" name="_scheme" value="dark" data-look-scheme><span><?= icon('moon', 16) ?><span class="sr-only"><?= esc(t('Тёмная')) ?></span></span></label>
    </div>
    <div class="segmented look-seg" role="radiogroup" aria-label="<?= esc(t('Экран')) ?>">
      <label title="<?= esc(t('Компьютер')) ?>"><input type="radio" name="_device" value="desktop" checked data-look-device><span><?= icon('desktop', 16) ?><span class="sr-only"><?= esc(t('Компьютер')) ?></span></span></label>
      <label title="<?= esc(t('Телефон')) ?>"><input type="radio" name="_device" value="phone" data-look-device><span><?= icon('phone', 16) ?><span class="sr-only"><?= esc(t('Телефон')) ?></span></span></label>
    </div>
    <span class="look-preview-title"><?= esc(t('Предпросмотр')) ?></span>
    <a class="btn-icon btn-icon-s" href="<?= esc($previewUrl) ?>" target="_blank" rel="noopener" data-look-open
       title="<?= esc(t('Открыть предпросмотр отдельно')) ?>" aria-label="<?= esc(t('Открыть предпросмотр отдельно')) ?>"><?= icon('external', 16) ?></a>
  </div>
  <?php /* Что не применится и почему — под экраном, а не в карточке цветов:
           так видно и для темы, присланной манифестом. Причины — из того же
           разбора, что рисует предпросмотр. */ ?>
  <ul class="look-why" data-look-why hidden></ul>
  <div class="look-stage" data-look-stage>
    <iframe src="<?= esc($previewUrl) ?>" title="<?= esc(t('Экран входа с этим оформлением')) ?>" loading="lazy" data-look-frame></iframe>
  </div>
  <p class="hint mt-8 mb-0"><?= esc(t('Предпросмотр обновляется сам. Кнопки в нём не нажимаются — это настоящий экран входа, только без отправки. Обложка появится после сохранения.')) ?></p>
</aside>
</div>
