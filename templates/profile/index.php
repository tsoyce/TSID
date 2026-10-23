<?php /** Карточка профиля (§5.1, вайрфрейм §10.6): ID-карта + плитки. */
use App\Controllers\ProfileController; ?>
<div class="page-head">
  <h1><?= esc(t('Профиль')) ?></h1>
  <a class="btn btn-secondary" href="/profile/edit"><?= esc(t('Редактировать')) ?></a>
</div>

<?php
/* Политика инсталляции может требовать телефон или пол. Требование мягкое:
   напоминание со ссылкой, а не запертая дверь — принцип «30 секунд» важнее. */
$missing = [];
if (App\Core\Setting::get('policy.require_phone') === '1' && empty($user['phone'])) $missing[] = t('телефон');
if (App\Core\Setting::get('policy.require_gender') === '1' && empty($user['gender'])) $missing[] = t('пол');
?>
<?php if ($missing !== []): ?>
<div class="mb-16">
  <?= callout(esc(t('Заполните, пожалуйста: :list', ['list' => implode(t(' и '), $missing)]))
      . '. <a href="/profile/edit">' . esc(t('Открыть редактирование →')) . '</a>', 'warning', true) ?>
</div>
<?php endif; ?>

<?php /* Удостоверение не лежит в потоке профиля: его показывают редко и по
         одному поводу — предъявить. Здесь только строка «кто вы», а карточка
         целиком открывается по нажатию и занимает всё внимание. Заодно номер,
         роль и MRZ перестают попадать в каждый скриншот профиля. */ ?>
<button class="pass-trigger mb-16" type="button" data-modal-open="pass-sheet">
  <?= avatar_html($user, 48) ?>
  <span class="tile-main">
    <span class="tile-title"><?= esc(user_name($user)) ?><?php if ($user['is_verified_badge']): ?>
      <span class="verified-badge" title="<?= esc(t('Подтверждённый аккаунт')) ?>"><?= icon('badge-check', 15) ?></span>
    <?php endif; ?></span>
    <?php /* Вторая строка не повторяет первую: у человека без имени
             `user_name()` возвращает почту, и плитка показывала один и тот же
             адрес дважды подряд. */ ?>
    <?php if (user_name($user) !== (string)($user['email'] ?? '') && !empty($user['email'])): ?>
    <span class="tile-sub"><?= esc($user['email']) ?></span>
    <?php endif; ?>
  </span>
  <span class="tile-end">
    <span class="hint"><?= esc(t('Удостоверение')) ?></span>
    <?= icon('chevron-right', 17, 'icon-muted') ?>
  </span>
</button>

<div class="bento">
  <!-- Безопасность -->
  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Безопасность')) ?></div>
    <div class="kpi-value fs-28"><?= (int)$score['percent'] ?>%</div>
    <div class="score-bar mt-8"><i style="width:<?= (int)$score['percent'] ?>%"></i></div>
    <ul class="check-list">
      <?php foreach ($score['items'] as $item): ?>
      <li class="<?= $item['ok'] ? 'yes' : 'no' ?>"><?= $item['ok'] ? icon('check', 15, 'icon-ok') : icon('circle', 15, 'icon-muted') ?> <?= esc($item['label']) ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="bc-footer"><a href="/profile/security"><?= esc(t('Настроить →')) ?></a></div>
  </div>

  <!-- Сервисы -->
  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Сервисы')) ?> (<?= count($services) ?>)</div>
    <?php if ($services === []): ?>
      <?= empty_state('portal', t('Пока ни одного входа в порталы'), '', 'pad-16') ?>
    <?php else: ?>
      <div class="stack">
      <?php foreach (array_slice($services, 0, 4) as $s): ?>
        <div class="row gap-8">
          <span class="badge <?= $s['status'] === 'active' ? 'badge-ok' : ($s['status'] === 'pending' ? 'badge-warn' : 'badge-danger') ?>">●</span>
          <span class="cell-main"><?= esc($s['name']) ?></span>
          <?php /* «—» вместо даты означало «никогда не входил», но рядом с
                   именем портала читалось как оборванная строка. Молчим. */ ?>
          <?php if (!empty($s['last_login_at'])): ?>
          <span class="hint nowrap"><?= esc(fmt_ago($s['last_login_at'])) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="bc-footer"><a href="/profile/services"><?= esc(t('Все сервисы →')) ?></a></div>
  </div>

  <!-- Сессии -->
  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Устройства')) ?> (<?= count($sessions) ?>)</div>
    <div class="stack">
    <?php foreach ($sessions as $s): ?>
      <div class="row gap-8">
        <?= icon(is_mobile_ua((string)$s['user_agent']) ? 'phone' : 'desktop', 16, 'icon-muted') ?>
        <span><?= esc($s['device_label'] ? device_text($s['device_label']) : t('Устройство')) ?></span>
        <?php if ((int)$s['id'] === $currentSessionId): ?><span class="badge badge-ink"><?= esc(t('текущее')) ?></span><?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <div class="bc-footer"><a href="/profile/sessions"><?= esc(t('Управлять →')) ?></a></div>
  </div>

  <!-- Последние входы -->
  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Последние входы')) ?></div>
    <?php if ($logins === []): ?>
      <?= empty_state('clock', t('История пока пуста'), '', 'pad-16') ?>
    <?php else: ?>
      <div class="stack">
      <?php foreach ($logins as $l): ?>
        <div class="row gap-8">
          <span class="hint nowrap"><?= esc(fmt_dt($l['created_at'], 'd.m H:i')) ?></span>
          <span class="hint mono"><?= esc(ip_masked($l['ip'])) ?></span>
          <span class="hint truncate"><?= esc(device_text(device_label((string)$l['user_agent']))) ?></span>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="bc-footer"><a href="/profile/activity"><?= esc(t('Вся история →')) ?></a></div>
  </div>
</div>

<?php /**
 * Реквизиты удостоверения. Вынесены из карточки: нужны редко, а места занимают
 * много. В QR — только номер аккаунта, ничего секретного: QR со скриншота
 * живёт вечно, и класть туда что-то, дающее доступ, нельзя.
 */ ?>
<?php
$idLevel = App\Core\AccessLevel::of($user);
[$latinLast, $latinFirst] = ProfileController::latinName($user);
?>
<div class="pass-sheet" id="pass-sheet" role="dialog" aria-modal="true"
     aria-label="<?= esc(t('Удостоверение')) ?>">
  <div class="pass-sheet-in">
    <div class="idcard">
      <?php /* Крестик живёт внутри карточки, а не на подложке: у листа снизу
               подложки почти нет — там нечего нажимать. */ ?>
      <button class="btn-icon btn-icon-s pass-close" type="button" data-modal-close
              aria-label="<?= esc(t('Закрыть')) ?>"><?= icon('x', 18) ?></button>

      <div class="idcard-top">
        <?php /* Не avatar_html(): тот рисует круг, а здесь рамка документа
                 84×108 — круг в неё не вписывается и вылезает по ширине.
                 Фото заполняет рамку, инициалы — тот же градиент, что у
                 круглого аватара, чтобы человек узнавал себя. */ ?>
        <div class="idcard-photo">
          <?php $photo = avatar_url($user, 512); ?>
          <?php if ($photo !== null): ?>
            <img src="<?= esc($photo) ?>" alt="">
          <?php else: ?>
            <span class="idcard-initials" style="background:<?= esc(avatar_gradient($user['public_id'] ?? '')) ?>">
              <?= esc(user_initials($user)) ?></span>
          <?php endif; ?>
        </div>
        <div class="flex-1">
          <div class="idcard-name"><?= esc(user_name($user)) ?></div>
          <div class="idcard-latin"><?= esc(trim($latinLast . ' ' . $latinFirst)) ?></div>
        </div>
      </div>

      <div class="idcard-fields">
        <?php if (!empty($user['account_number'])): ?>
        <?php /* Скрыто по умолчанию: карточку показывают с экрана и
                 фотографируют. Раскрывается нажатием, а не наведением. */ ?>
        <div>
          <div class="idcard-k"><?= esc(t('Номер аккаунта')) ?></div>
          <button class="idcard-hide idcard-v mono" type="button"
                  data-secret="<?= esc(App\Core\AccountNumber::format($user['account_number'])) ?>">
            <span class="dots">••• ••• •••</span>
            <span class="reveal"><?= esc(t('показать')) ?></span>
          </button>
        </div>
        <?php endif; ?>
        <div>
          <div class="idcard-k"><?= esc(t('Уровень доступа')) ?></div>
          <button class="idcard-hide idcard-v" type="button"
                  data-secret="<?= esc(App\Core\AccessLevel::title($idLevel) . ' · ' . $idLevel) ?>">
            <span class="dots">•••••••</span>
            <span class="reveal"><?= esc(t('показать')) ?></span>
          </button>
        </div>
        <div>
          <div class="idcard-k"><?= esc(t('В системе с')) ?></div>
          <div class="idcard-v mono"><?= esc(fmt_dt($user['created_at'], 'd.m.Y')) ?></div>
        </div>
        <div>
          <div class="idcard-k">Email</div>
          <div class="idcard-v"><?= $user['email_verified_at']
              ? '<span class="badge badge-ok">' . esc(t('подтверждён')) . '</span>'
              : '<span class="badge badge-warn">' . esc(t('не подтверждён')) . '</span>' ?></div>
        </div>
      </div>

      <?php /* QR стоит рядом с MRZ, а не в шапке: и то и другое читает машина,
               а не человек. В коде только номер аккаунта — QR со скриншота
               живёт вечно, и класть туда что-то, дающее доступ, нельзя. */ ?>
      <div class="idcard-machine">
        <?php if (!empty($user['account_number'])): ?>
        <div class="idcard-qr" data-qr="<?= esc('sso:' . $user['account_number']) ?>"></div>
        <?php endif; ?>
        <button class="idcard-mrz is-hidden" type="button"
                data-secret="<?= esc(ProfileController::mrz($user)) ?>">
          <span class="dots">•••••••••••••••••••••••••••••••••••••••••••</span>
          <span class="reveal"><?= esc(t('показать')) ?></span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php /* Генератор QR подключается здесь, а не в общем макете: он весит 2300
         строк и нужен ровно двум страницам. До этого его не было в профиле
         вовсе — плашка QR в удостоверении рисовалась пустым белым квадратом,
         и заметить это можно было только открыв карточку. */ ?>
<script src="<?= esc(asset('assets/js/qrcode.js')) ?>" defer></script>
