<?php /** Вход (§5.1). $client — контекст «Вы входите в X». */ ?>
<?php $bridge = App\Core\Bridge::brandingOf($client); ?>
<?php if ($client): ?>
<?php /* «Мостик»: человек пришёл с чужого сайта, и если экран выглядит
         совершенно иначе — это читается как подмена. Знак портала стоит РЯДОМ
         с нашим, а не вместо: слева тот, откуда пришли, справа тот, кто
         спрашивает пароль. */ ?>
<div class="connector-head mb-24">
  <?= portal_connector($client) ?>
  <div class="connector-caption">
    <?= esc(t('login.context')) ?> <b><?= esc($client['name']) ?></b>
    <?php if ($bridge && $bridge['tagline']): ?>
    <div class="hint"><?= esc($bridge['tagline']) ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php /* «Войти и привязать» (v4.88): сервис ждёт входа в свой аккаунт. Плашка,
         а не всплывающее сообщение: тост исчезает, а объяснение, зачем человек
         снова на экране входа, должно стоять, пока он вводит пароль. */
if ($pendingLink = App\Core\PendingLink::peek()): ?>
<div class="callout callout-info mb-16" data-pending-link>
  <div class="flex-1"><?= esc(t('Войдите в свой аккаунт — :provider привяжется к нему.', ['provider' => $pendingLink['provider_title']])) ?>
    <a class="hint" href="/social/signup/cancel"><?= esc(t('Отменить')) ?></a></div>
</div>
<?php endif; ?>

<?= auth_tabs('login') ?>

<?php
/* Состав экрана задаёт владелец в «Оформлении сайта». Выключить всё нельзя —
   там стоит запрет, — поэтому формы без единого способа входа здесь не бывает.
   Разделитель рисуется только МЕЖДУ двумя видимыми способами: при одном
   оставшемся под ним висела бы черта в никуда. */
/* Портал может оставить у себя не все способы (v4.71): его «Экран входа».
   Запереть он не может — Bridge::methodsOf() вернёт общий состав, если от
   выбора портала ничего не осталось. */
$mPassword = App\Core\Bridge::methodShown($client ?? null, 'password');
$mMagic    = App\Core\Bridge::methodShown($client ?? null, 'magic');
$mPasskey  = App\Core\Bridge::methodShown($client ?? null, 'passkey');
$social    = App\Core\Bridge::methodShown($client ?? null, 'social') ? App\Social\Provider::enabled() : [];
/* «Сервисы — первыми» (v4.86): кнопки над формой, как у Google, Apple и X, —
   в окне поверх сайта они иначе оказываются ниже первого экрана. */
$socialFirst = $social !== [] && App\Core\Bridge::socialFirst($client ?? null);
ob_start();
/* v4.86: фирменный знак и «Продолжить с …» по центру, во всю ширину — как у
   Google, Apple и X; «продолжить», а не «войти»: той же кнопкой и заводят
   аккаунт. Четыре сервиса на широком экране — два столбца и одно имя: полная
   подпись в половину ширины не влезает; на телефоне и во фрейме столбец один
   — и подпись полная. */
?>
<div class="oauth-row<?= count($social) > 3 ? ' oauth-row-2' : '' ?>">
  <?php foreach ($social as $p): ?>
    <a class="oauth-btn" href="/social/<?= esc($p->id()) ?>/start" data-social="<?= esc($p->id()) ?>">
      <?= icon($p->icon(), 20) ?><span class="oauth-long"><?= esc(t('Продолжить с :name', ['name' => $p->title()])) ?></span><span class="oauth-short"><?= esc($p->title()) ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php $oauthRow = (string)ob_get_clean(); ?>

<?php if ($socialFirst): ?>
<?= $oauthRow ?>
<?php if ($mPassword || $mMagic || $mPasskey): ?>
<div class="auth-divider"><?= esc(t('login.or')) ?></div>
<?php endif; ?>
<?php endif; ?>

<?php if ($mPassword): ?>
<?php /* Логин и пароль — основная форма и первое, что видно.
         Порядок был другим: наверх выносился ключ устройства, потому что
         пароль в системе выключен по умолчанию. Но перенос людей с уже
         работающего сервиса идёт логинами и хэшами паролей, и почты у них
         может не быть вовсе — своего почтового сервера у портала часто нет.
         Для таких людей ссылка на почту не работает в принципе, а пароль —
         единственный способ войти. */ ?>
<form method="post" action="/login">
  <?= csrf_field() ?><?= App\Core\Resume::field() ?>
  <?= field_text('identity', t('login.identity'), $identity ?? '', [
        'id' => 'identity', 'autocomplete' => 'username', 'autofocus' => true, 'required' => true]) ?>
  <?= field_password('password', t('common.password'), ['autofocus' => false]) ?>
  <?php if (!empty($captcha)): ?><?= captcha_field('captcha-pw') ?><?php endif; ?>
  <div class="row-between mb-12">
    <label class="checkbox mb-0"><input type="checkbox" name="remember" value="1"><span><?= esc(t('login.remember')) ?></span></label>
    <a href="/recover" class="hint"><?= esc(t('login.forgot')) ?></a>
  </div>
  <button class="btn btn-primary btn-block" type="submit"><?= esc(t('login.submit')) ?></button>
</form>
<?php endif; ?>

<?php if ($mPassword && ($mMagic || $mPasskey)): ?>
<div class="auth-divider"><?= esc(t('login.or')) ?></div>
<?php endif; ?>

<?php if ($mMagic): ?>
<?php /* Вход по ссылке — одной строкой: поле и кнопка рядом. Способ нужный,
         но не основной, и разворачивать его в отдельную форму на пол-экрана
         значит спорить с тем, ради чего человек сюда пришёл. */ ?>
<form method="post" action="/login/link">
  <?= csrf_field() ?><?= App\Core\Resume::field() ?>
  <div class="field mb-8">
    <label for="magic-email"><?= esc(t('login.magic_label')) ?></label>
    <div class="inline-field">
      <?php /* Поле и кнопка в одну строку — это не обычное поле, а inline-field:
               подпись стоит над парой, а не над полем. Объявлением не рисуется
               нарочно, узор встречается один раз. */ ?>
      <input class="input" type="email" id="magic-email" name="email"
             placeholder="you@example.ru" autocomplete="email">
      <button class="btn btn-secondary" type="submit">
        <?= icon('mail', 16) ?> <?= esc(t('login.magic_send')) ?>
      </button>
    </div>
  </div>
  <p class="hint"><?= esc(t('Ссылка живёт 15 минут и открывается один раз. Пароль не нужен.')) ?></p>
</form>
<?php endif; ?>

<?php if ($mPasskey): ?>
<?php /* Ключ устройства — рядом со ссылкой, а не сверху: у него та же роль
         «войти без пароля», и держать их в разных концах экрана незачем. */ ?>
<button class="btn btn-secondary btn-passkey btn-block mt-8" type="button" data-passkey-login>
  <?= icon('key', 17) ?> <?= esc(t('login.passkey')) ?>
</button>
<div id="passkey-status" class="hint mt-8 text-center"></div>
<script src="<?= esc(asset('assets/js/passkey.js')) ?>" defer></script>
<?php endif; ?>

<?php if ($social !== [] && !$socialFirst): ?>
<div class="auth-divider"><?= esc(t('или через сервис')) ?></div>
<?= $oauthRow ?>
<?php endif; ?>
