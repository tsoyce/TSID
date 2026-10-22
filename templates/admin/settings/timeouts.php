<?php /** Раздел настроек «Сроки» (§5.6).
 *  Секунды человеку не читаются: 2592000 — это месяц, но узнать это можно
 *  только посчитав. Рядом с полем стоит перевод на язык, а границы объясняются
 *  словами, а не отказом после сохранения. */
use App\Core\Setting;

/** «43200» → «12 часов». Считаем от крупного к мелкому и берём первое целое. */
$human = static function (int $sec): string {
    foreach ([[86400, 'дн'], [3600, 'ч'], [60, 'мин']] as [$unit, $name]) {
        if ($sec >= $unit && $sec % $unit === 0) return intdiv($sec, $unit) . ' ' . t($name);
    }
    if ($sec >= 86400) return round($sec / 86400, 1) . ' ' . t('дн');
    if ($sec >= 3600) return round($sec / 3600, 1) . ' ' . t('ч');
    return $sec . ' ' . t('сек');
};
?>
  <div class="bento-card">
    <div class="bc-title"><?= esc(t('Сроки')) ?> <span class="hint fw-400"><?= esc(t('(в секундах)')) ?></span></div>
    <form method="post" action="/admin/settings/save">
      <?= csrf_field() ?><input type="hidden" name="section" value="timeouts">
      <div class="grid-3">
        <?php
        $labels = [
            'session' => [t('Сессия SSO'), t('Сколько живёт вход без «запомнить меня»')],
            'remember' => [t('«Запомнить меня»'), t('Срок долгой сессии')],
            'access_token' => [t('Access-токен'), t('Короче — быстрее подействует блокировка на порталах')],
            'token_email_verify' => [t('Ссылка подтверждения почты'), t('Из письма о регистрации')],
            'token_password_reset' => [t('Ссылка сброса пароля'), t('Из письма о восстановлении')],
        ];
        foreach (\App\Controllers\Admin\SettingsController::TIMEOUTS as $key => [$min, $max, $default]):
            $value = (int)Setting::timeout($key, $default);
            /* Границы словами, а не только атрибутами: браузер о `min`
               промолчит при вставке из буфера, а сервер обрежет молча — и
               человек увидит не то, что вводил, не поняв почему. */
            $hint = t($labels[$key][1]) . ' · ' . t('сейчас :now, допустимо :min — :max', [
                'now' => $human($value), 'min' => $human($min), 'max' => $human($max),
            ]);
            echo field_text('t_' . $key, t($labels[$key][0]), (string)$value, [
                'mask' => '#########', 'inputmode' => 'numeric', 'mono' => true,
                'max' => 9, 'hint' => $hint, 'class' => 'mb-0',
            ]);
        endforeach; ?>
      </div>

      <?php /* Пояс установки задавался только в файле конфигурации, то есть
               по ssh. Он решает, в каком времени человек без своего пояса
               видит даты и в каком приходят письма; у новых аккаунтов он же
               становится собственным. Здесь ему и место — рядом со сроками. */ ?>
      <div class="bc-title mt-24"><?= esc(t('Часовой пояс установки')) ?></div>
      <?= field_select('timezone', t('По умолчанию'), tz_options(), Setting::timezone(), [
            'class' => 'mw-xs',
            'hint' => t('В нём показываются даты тому, у кого свой пояс не выбран, и в нём же уходят письма. Свой пояс человек ставит в настройках аккаунта.')]) ?>
      <p class="hint"><?= esc(t('Сейчас на сервере :now.', ['now' => fmt_dt(now(), 'd.m.Y H:i')])) ?></p>
      <?= form_actions() ?>
    </form>
  </div>
