<?php /** Раздел настроек «Обслуживание» (§5.6).
 *  Вырезан из общего экрана: свой адрес, одна форма, одна кнопка. */
use App\Core\Setting;
?>
  <div class="bento-card">
    <form method="post" action="/admin/settings/save">
      <?= csrf_field() ?><input type="hidden" name="section" value="maintenance">
      <?= field_toggle('enabled', t('Режим обслуживания'), Setting::get('maintenance.enabled') === '1',
            t('Все получают 503, кроме адресов из списка ниже. Вход в админку тоже закрыт — список это единственная дверь.')) ?>
      <?= field_text('allowlist', t('Allowlist IP'), (string)Setting::get('maintenance.allowlist', ''), [
            'mono' => true, 'placeholder' => '203.0.113.5, 198.51.100.7', 'max' => 400,
            'note' => t('(через запятую)'),
            'hint' => t('Пусто при включённом режиме означает, что внутрь не попадёт никто.')]) ?>
      <p class="hint mt-8"><?= esc(t('Ваш текущий IP:')) ?> <code><?= esc(request_ip()) ?></code> <?= esc(t('— не забудьте его в allowlist, если включаете.')) ?></p>
      <?= form_actions() ?>
    </form>
  </div>
