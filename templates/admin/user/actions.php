<?php /**
 * Действия над аккаунтом.
 *
 * Раздел был ПОЛНОСТЬЮ невидим. Разметка осталась от карточки со вкладками:
 * обёртка `.tab-pane` спрятана в CSS до тех пор, пока скрипт не повесит на неё
 * `.active` по нажатию на вкладку. Вкладки убрали в v4.30 — разделы стали
 * страницами, — а обёртка осталась, и активировать её стало нечем. Страница
 * отдавала 200, подпись раздела рисовалась, содержимое не появлялось никогда.
 *
 * v4.92: вместо стопки одинаковых кнопок во всю ширину — карточки по смыслу:
 * «Помочь войти», «Доступ к аккаунту», «Номер аккаунта». У каждого действия
 * строкой сказано, что оно сделает. Удаление больше не стоит сплошной красной
 * кнопкой среди безобидных ссылок: оно в «Доступе», рядом с блокировкой.
 */
$uid = (int)$u['id'];
$isSuper = App\Core\Auth::isSuperadmin();
$targetSuper = in_array('superadmin', App\Models\User::globalRoleSlugs($uid), true);
$fresh = !empty($_SESSION['fresh_login_link']) && (int)$_SESSION['fresh_login_link']['user'] === $uid
    ? $_SESSION['fresh_login_link'] : null;
unset($_SESSION['fresh_login_link']);
$act = static fn(string $action, string $label, string $btn, string $confirm = ''): string =>
    '<form method="post" action="/admin/users/' . $uid . '/action"'
    . ($confirm !== '' ? ' data-confirm="' . esc($confirm) . '"' : '') . '>'
    . csrf_field() . '<input type="hidden" name="action" value="' . esc($action) . '">'
    . '<button class="btn btn-s ' . $btn . '" type="submit">' . esc($label) . '</button></form>';
?>
<div class="bento">
  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Помочь войти')) ?></div>
    <div class="act-list">
      <div class="act-item">
        <div class="act-text">
          <div class="act-name"><?= esc(t('Ссылка сброса пароля')) ?></div>
          <div class="hint"><?= esc(!empty($u['email'])
              ? t('Уйдёт письмом на :email — новый пароль человек задаст сам.', ['email' => $u['email']])
              : t('Почты у аккаунта нет — ссылка появится здесь, передадите её сами.')) ?></div>
        </div>
        <?= $act('reset_link', !empty($u['email']) ? t('Отправить') : t('Создать'), 'btn-secondary') ?>
      </div>
      <?php /* Ссылка входа — для поддержки, когда человек не помнит пароль и
               не имеет доступа к почте. Владельцу уходит уведомление. */ ?>
      <div class="act-item">
        <div class="act-text">
          <div class="act-name"><?= esc(t('Ссылка для входа')) ?></div>
          <div class="hint"><?= esc(t('Одноразовая, на 15 минут — когда нет ни пароля, ни почты. Владелец получит уведомление.')) ?></div>
        </div>
        <?= $act('login_link', t('Создать'), 'btn-secondary',
              t('Создать одноразовую ссылку для входа в этот аккаунт? Владелец получит уведомление.')) ?>
      </div>
      <?php if (!$u['email_verified_at'] && !empty($u['email'])): ?>
      <div class="act-item">
        <div class="act-text">
          <div class="act-name"><?= esc(t('Подтвердить почту')) ?></div>
          <div class="hint"><?= esc(t('Когда владение адресом подтверждено другим путём — письмо не дошло, а человек рядом.')) ?></div>
        </div>
        <?= $act('verify_email', t('Подтвердить'), 'btn-secondary') ?>
      </div>
      <?php endif; ?>
      <?php if ($isSuper): ?>
      <div class="act-item">
        <div class="act-text">
          <div class="act-name"><?= esc(t('Глазами владельца')) ?></div>
          <div class="hint"><?= esc(t('Профиль откроется так, как его видит человек. Каждый шаг — в журнал; пароль и почту в этом режиме не сменить.')) ?></div>
        </div>
        <form method="post" action="/admin/users/<?= $uid ?>/action">
          <?= csrf_field() ?><input type="hidden" name="action" value="impersonate">
          <button class="btn btn-s btn-secondary" type="submit"><?= icon('eye', 15) ?> <?= esc(t('Смотреть')) ?></button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($fresh !== null): $lk = ($fresh['kind'] ?? '') === 'reset'; ?>
    <?= field_copy('login-link', $lk ? t('Ссылка сброса пароля') : t('Ссылка входа'), $fresh['url'], ['class' => 'mt-16',
          'note' => $lk ? t('(одноразовая — у аккаунта нет почты)') : t('(одноразовая, 15 минут)')]) ?>
    <?php endif; ?>
    <p class="hint mt-16"><?= esc(t('Пароль руками не задаётся никогда — только ссылка сброса на почту пользователя.')) ?></p>
  </div>

  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Доступ к аккаунту')) ?></div>
    <?php if ($targetSuper): ?>
    <p class="hint"><?= esc(t('Это суперадмин: заблокировать или удалить его нельзя. Сначала снимите роль в разделе «Роли».')) ?></p>
    <?php elseif ($u['status'] === 'deleted'): ?>
    <p class="hint"><?= esc(t('Аккаунт помечен на удаление :when. Данные обезличатся через 14 дней после этого; войдёт раньше — аккаунт вернётся.', ['when' => fmt_dt($u['deleted_at'] ?? null)])) ?></p>
    <?php else: ?>
    <div class="act-list">
      <div class="act-item">
        <div class="act-text">
          <?php if ($u['status'] === 'blocked'): ?>
          <div class="act-name"><?= esc(t('Аккаунт заблокирован')) ?> <span class="badge badge-danger"><?= esc(t('заблокирован')) ?></span></div>
          <div class="hint"><?= esc(t('Войти нельзя ни сюда, ни в порталы. Разблокируете — вход откроется снова.')) ?></div>
          <?php else: ?>
          <div class="act-name"><?= esc(t('Заблокировать')) ?></div>
          <div class="hint"><?= esc(t('Вход закроется, сессии завершатся, порталы получат событие блокировки.')) ?></div>
          <?php endif; ?>
        </div>
        <?= $u['status'] === 'blocked'
            ? $act('unblock', t('Разблокировать'), 'btn-secondary')
            : $act('block', t('Заблокировать'), 'btn-danger-ghost', t('Заблокировать? Порталы получат user.blocked, сессии закроются.')) ?>
      </div>
      <div class="act-item">
        <div class="act-text">
          <div class="act-name"><?= esc(t('Удалить аккаунт')) ?></div>
          <div class="hint"><?= esc(t('Пропадёт из списков, порталы его отключат. Через 14 дней данные обезличатся; войдёт раньше — аккаунт вернётся.')) ?></div>
        </div>
        <?= $act('delete', t('Удалить'), 'btn-danger-ghost', t('Удалить аккаунт? Анонимизация через 14 дней, порталы получат user.deleted.')) ?>
      </div>
    </div>
    <?php endif; ?>
    <?php /* Окончательное удаление живёт отдельно и за паролем: обычное
             помечает аккаунт и держит 14 дней, а это сносит без следа. */ ?>
    <?php if (App\Core\Auth::isAdmin() && !$targetSuper): ?>
    <p class="hint mt-16"><?= esc(t('Стереть сразу и без следа —')) ?> <a class="link-danger" href="/admin/users/<?= $uid ?>/danger"><?= esc(t('в опасной зоне')) ?></a>.</p>
    <?php endif; ?>
  </div>

  <?php /* Номер аккаунта — то, что человек называет в поддержке. Менять
           его может только суперадмин и только вручную. */ ?>
  <?php if ($isSuper): ?>
  <div class="bento-card col-6">
    <div class="bc-title"><?= esc(t('Номер аккаунта')) ?></div>
    <?php /* Клетки вместо строки. Прежде здесь стояло обычное поле: чтобы
             сменить номер, надо было знать формат наизусть, самому
             поставить тире и самому посчитать контрольную цифру — а на
             ошибке получить отказ уже после отправки. Клетки задают длину
             собой, контрольная считается на месте. */ ?>
    <form method="post" action="/admin/users/<?= $uid ?>/action">
      <?= csrf_field() ?><input type="hidden" name="action" value="account_number">
      <?= field_account_number('account_number', t('Номер, который человек называет в поддержке'), (string)($u['account_number'] ?? ''), [
            /* Подсказку считает сервер: у одного человека она всегда одна и
               та же, потому что выводится из sub и уровня доступа. Первая
               цифра несёт уровень — это подсказка, а не право. */
            'suggest' => App\Core\AccountNumber::suggest(
                (string)$u['public_id'], App\Core\AccessLevel::of($u)),
          ]) ?>
      <button class="btn btn-secondary" type="submit"><?= esc(t('Сменить номер')) ?></button>
    </form>
    <p class="hint mt-8"><?= esc(t('Прежний номер останется в журнале — обращения по нему не потеряются.')) ?></p>
  </div>
  <?php endif; ?>
</div>
