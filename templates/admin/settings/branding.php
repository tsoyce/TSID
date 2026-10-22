<?php /** Раздел настроек «Брендинг» (§5.6).
 *  То, что видно ВЕЗДЕ: имя, цвет, логотип, favicon. Всё, что видит только не
 *  вошедший, — в «Оформлении сайта». */
use App\Core\Palette;
use App\Core\Setting;

/* Превью считаем тем же кодом, что и рисует страницы, — иначе владелец увидит
   здесь одно, а на сайте другое. */
$accentRaw = (string)Setting::get('branding.accent', '');
$rep = Palette::report($accentRaw !== '' ? $accentRaw : '#1D4ED8');
$repDark = Palette::report($accentRaw !== '' ? $accentRaw : '#60A5FA', true);
?>
  <div class="bento-card">
    <form method="post" action="/admin/settings/save" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="section" value="branding">

      <div class="grid-2">
        <?= field_text('name', t('Название инсталляции'), Setting::appName(), [
              'max' => 120, 'hint' => t('Стоит в шапке, в письмах и в заголовке вкладки')]) ?>
        <?= field_text('footer', t('Текст в подвале'), (string)Setting::get('branding.footer', ''), [
              'max' => 200, 'placeholder' => Setting::appName(),
              'hint' => t('Пусто — подставится название')]) ?>
      </div>

      <?= field_color('accent', t('Акцентный цвет'), $accentRaw, $rep['ink'], [
            'note' => t('(hex; пусто = чернильный синий по умолчанию)'),
            'placeholder' => '#1D4ED8']) ?>
      <div class="accent-preview mb-8">
        <span class="ap-chip" style="background:<?= esc($rep['ink']) ?>;color:<?= esc($rep['on_ink']) ?>"><?= esc(t('Кнопка')) ?></span>
        <span class="ap-chip ap-chip-dark" style="background:<?= esc($repDark['ink']) ?>;color:<?= esc($repDark['on_ink']) ?>"><?= esc(t('Тёмная тема')) ?></span>
        <?php /* Образец лежит на подложке СВОЕЙ темы, а не на подложке
                 страницы: иначе он показывает не то, о чём рассказывает.
                 Тёмный образец был вовсе не нарисован — про ссылки в тёмной
                 теме предпросмотр молчал, хотя для кнопки чип уже был. */ ?>
        <span class="ap-link" style="background:<?= esc(Palette::surface(false)) ?>;color:<?= esc($rep['ink_text']) ?>"><?= esc(t('Ссылка')) ?></span>
        <span class="ap-link" style="background:<?= esc(Palette::surface(true)) ?>;color:<?= esc($repDark['ink_text']) ?>"><?= esc(t('Ссылка в тёмной')) ?></span>
      </div>
      <p class="hint mb-16">
        <?= esc(t('Цвет текста на кнопке и оттенок ссылки подбираются автоматически по контрасту: светлый акцент получит тёмную надпись, тёмный — светлую. Сейчас')) ?>
        <span class="mono"><?= esc(number_format($rep['contrast_fill'], 1)) ?>:1</span> <?= esc(t('на кнопке и')) ?> <span class="mono"><?= esc(number_format($rep['contrast_text'], 1)) ?>:1</span> <?= esc(t('у ссылки')) ?>
        <?= esc(t('при норме 4.5.')) ?>
        <?php if ($rep['adjusted']): ?>
        <?= esc(t('Для текста акцент затемнён до')) ?> <span class="mono"><?= esc($rep['ink_text']) ?></span> —
        <?= esc(t('в исходном виде ссылки были бы нечитаемы.')) ?>
        <?php endif; ?>
      </p>

      <div class="grid-2">
        <?= field_image('logo', t('Логотип'), branding_logo_html(), (bool)Setting::get('branding.logo_v'), [
              'note' => t('(png, jpeg или webp, до 2 МБ)'),
              'remove' => t('Убрать логотип (вернётся «● Название»)'),
              'hint' => t('Впишется в высоту шапки, прозрачность сохраняется')]) ?>
        <?= field_image('favicon', 'Favicon',
              '<img src="' . esc(branding_favicon_href()) . '" alt="" width="24" height="24">',
              (bool)Setting::get('branding.favicon_v'), [
              'note' => t('(квадрат, до 1 МБ)'),
              'remove' => t('Убрать favicon (вернётся дефолтный)'),
              'hint' => t('Обрежем в 64×64')]) ?>
      </div>

      <?php /* Панель на экране входа переехала в «Оформление сайта»: она
               видна только не вошедшему, а логотип и favicon — везде. */ ?>
      <p class="hint"><?= esc(t('Обложка и подпись на экране входа, витрина на главной и состав формы — в разделе «Оформление сайта».')) ?>
        <a href="/admin/appearance"><?= esc(t('Открыть →')) ?></a></p>
      <p class="hint mt-8"><?= esc(t('Цвет пишется в CSS-переменную --ink — весь интерфейс перекрашивается одной строкой (§10.3).')) ?></p>
      <?= form_actions() ?>
    </form>
  </div>
