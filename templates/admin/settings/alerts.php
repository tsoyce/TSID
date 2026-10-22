<?php /** Раздел настроек «Уведомления» (§5.6).
 *  Письма ВЛАДЕЛЬЦУ про установку. Уведомления человеку про его собственный
 *  аккаунт живут в профиле и настраиваются там же — это разные адресаты, и
 *  сводить их в один экран значило бы путать «мой аккаунт» и «моя установка». */
use App\Core\AdminNotify;
use App\Core\Setting;

$to = AdminNotify::recipients();
?>
  <form method="post" action="/admin/settings/save">
    <?= csrf_field() ?><input type="hidden" name="section" value="alerts">
    <div class="bento">

      <div class="bento-card col-7">
        <div class="bc-title"><?= esc(t('О чём писать')) ?></div>
        <p class="hint mb-16"><?= esc(t('Всё, кроме сторожа, выключено по умолчанию: письмо о каждой регистрации нужно не всякой установке, а той, где регистрируются сотнями, — точно нет. Сторож пишет, только когда SSO перестал отвечать по стандартным адресам, — такое письмо нужно всегда.')) ?></p>
        <?php foreach (AdminNotify::EVENTS as $key => [$label, $why]): ?>
        <?= field_toggle('ev_' . str_replace('.', '_', $key), t($label),
              \App\Core\AdminNotify::enabled($key), t($why)) ?>
        <?php endforeach; ?>
      </div>

      <div class="bento-card col-5">
        <div class="bc-title"><?= esc(t('Кому')) ?></div>
        <?= field_textarea('to', t('Адреса'), (string)Setting::get('notify.admin.to', ''), [
              'rows' => 2, 'mono' => true, 'placeholder' => "admin@example.com\nops@example.com",
              'hint' => t('Через запятую или по одному на строку. Пусто — владельцу установки.')]) ?>
        <p class="hint">
          <?php if ($to === []): ?>
            <?= icon('alert-triangle', 15, 'icon-warn') ?>
            <?= esc(t('Писать сейчас некому: адреса не заданы, а у владельца нет почты.')) ?>
          <?php else: ?>
            <?= esc(t('Сейчас письма уйдут на: :list', ['list' => implode(', ', $to)])) ?>
          <?php endif; ?>
        </p>

        <?php /* Половина смысла раздела. Бот, заводящий пятьсот аккаунтов, не
                 должен прислать пятьсот писем: тогда владелец заведёт правило
                 «в папку», и уведомления перестанут работать ровно в тот день,
                 когда понадобятся. */ ?>
        <div class="bc-title mt-24"><?= esc(t('Чтобы не завалило')) ?></div>
        <?= field_text('window', t('Не чаще одного письма в'), (string)AdminNotify::windowMinutes(), [
              'type' => 'number', 'low' => 1, 'high' => 1440, 'mono' => true, 'class' => 'mw-xs',
              'note' => t('(минут)'),
              'hint' => t('На каждый вид события отдельно. Остальные случившиеся за это время не пропадут: они приедут строкой «и ещё столько-то» в следующем письме.')]) ?>
      </div>

      <div class="bento-card col-12">
        <p class="hint mb-0"><?= esc(t('Уведомления о своём аккаунте — вход с нового устройства, смена пароля — настраиваются каждым человеком у себя в профиле. Здесь только то, что касается установки целиком.')) ?></p>
      </div>
    </div>
    <?= form_actions() ?>
  </form>
