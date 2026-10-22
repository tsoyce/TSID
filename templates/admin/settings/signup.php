<?php /** Раздел настроек «Регистрация» (§5.6).
 *  Отрезан от «Политик» в v4.21: там было девять карточек и двадцать восемь
 *  полей — две с половиной высоты экрана под одной подписью из пяти
 *  несвязанных тем. Оболочка затем и заведена, чтобы у темы был свой адрес. */
use App\Core\Captcha;
use App\Core\LoginName;
use App\Core\Setting;
?>
  <?php /* Четыре независимые темы — четыре карточки в сетке, а не одна лента
           на 560px посреди пустого экрана. Форма при этом одна: сетка живёт
           внутри неё, поэтому сохранение по-прежнему одной кнопкой. */ ?>
  <form method="post" action="/admin/settings/save">
    <?= csrf_field() ?><input type="hidden" name="section" value="signup">
    <div class="bento">

      <?php /* Политика новых аккаунтов: дверь и роль за ней. Обе настройки по
               умолчанию оставляют поведение прежним — регистрация открыта,
               роль «Пользователь», — но теперь их можно поменять, а не только
               прочитать в коде. */ ?>
      <div class="bento-card col-6">
        <div class="bc-title"><?= esc(t('Новые аккаунты')) ?></div>
        <?= field_toggle('signup_open', t('Открытая регистрация'), App\Core\Signup::open(),
              t('Выключено — завести аккаунт можно только по ссылке-приглашению. Вход, соцвход и перенос людей с портала это не трогает: там аккаунт заводите вы.')) ?>
        <?= field_select('default_role', t('Роль нового аккаунта'),
              App\Core\Signup::roleChoices(), App\Core\Signup::defaultRoleSlug(),
              ['hint' => t('Её получает каждый, кто появился сам: регистрацией, соцвходом, импортом или переносом с портала. В списке только роли не старше «Пользователя» — раздать администратора выпадающим списком нельзя.')]) ?>
        <?php if (!App\Core\Signup::open()): ?>
        <p class="hint mt-8"><a href="/admin/tools#invites"><?= esc(t('Выдать ссылку-приглашение →')) ?></a></p>
        <?php endif; ?>
      </div>

      <?php /* Подтверждение почты до входа (v4.72). Живой случай 21.09:
               аккаунты на адреса-псевдонимы входили сразу после регистрации. */ ?>
      <div class="bento-card col-6">
        <div class="bc-title"><?= esc(t('Подтверждение почты')) ?></div>
        <?= field_toggle('verify_email', t('Требовать подтверждение почты у новых аккаунтов'), App\Core\EmailGate::active(),
              t('Новый аккаунт входит только после подтверждения. В письме — и код, и кнопка: кому удобнее ввести шесть цифр, вводит, кому удобнее нажать — нажимает.')) ?>
        <p class="hint"><?= esc(t('Только для новых аккаунтов, заведённых регистрацией. Действующие аккаунты, вход через сервисы, перенос людей с порталов, импорт и API это не затрагивает. Выключите — и ждущие подтверждения смогут войти.')) ?></p>
      </div>

      <div class="bento-card col-6">
        <div class="bc-title"><?= esc(t('Кому можно регистрироваться')) ?></div>
        <?= field_textarea('email_domains', t('Разрешённые домены почты'), (string)Setting::get('policy.email_domains', ''), [
              'rows' => 2, 'mono' => true, 'placeholder' => 'example.com, example.org',
              'hint' => t('Пусто — любой домен. Поддомены считаются своими: mail.example.com пройдёт по правилу example.com.')]) ?>
        <p class="hint">
          <?php if (App\Core\EmailDomains::restricted()): ?>
            <?= esc(t('Сейчас зарегистрироваться могут только с :list.', ['list' => implode(', ', array_map(static fn(string $d): string => '@' . $d, App\Core\EmailDomains::allowed()))])) ?>
          <?php else: ?>
            <?= esc(t('Сейчас зарегистрироваться может любой, у кого есть почта.')) ?>
          <?php endif; ?>
        </p>
        <p class="hint"><?= esc(t('Ограничение действует только на самостоятельную регистрацию. Приглашение, импорт, SCIM и Admin API его не спрашивают: там аккаунт заводите вы.')) ?></p>
      </div>

      <?php /* Замок — не про вход, а про то, что происходит ПОСЛЕ него.
               Ноль по умолчанию: устанавливать чужую привычку запирать экран
               не наше дело. */ ?>
      <div class="bento-card col-6">
        <div class="bc-title"><?= esc(t('Имя для входа')) ?></div>
        <div class="grid-2">
          <?= field_text('login_min', t('Минимум символов'), (string)LoginName::min(), [
                'type' => 'number', 'low' => LoginName::MIN_FLOOR, 'high' => LoginName::MAX_CEIL,
                'mono' => true]) ?>
          <?= field_text('login_max', t('Максимум'), (string)LoginName::max(), [
                'type' => 'number', 'low' => LoginName::MIN_FLOOR, 'high' => LoginName::MAX_CEIL,
                'mono' => true]) ?>
        </div>
        <?= field_textarea('login_reserved', t('Занять имена'), (string)Setting::get('policy.login_reserved', ''), [
              'rows' => 2, 'mono' => true, 'placeholder' => "director\nhr\nbot",
              'hint' => t('Через запятую или по одному на строку — поверх :n имён, занятых всегда (admin, root, support, noreply и подобные).', ['n' => count(LoginName::RESERVED_DEFAULT)])]) ?>
        <p class="hint"><?= esc(t('Набор символов не настраивается: логин уезжает в claim, в адреса и в чужие конфиги. Латиница, цифры, точка, дефис, подчёркивание — и не по краям.')) ?></p>
      </div>

      <div class="bento-card col-6">
        <div class="bc-title"><?= esc(t('Капча')) ?></div>
        <?php /* Порог, а не «включить всем»: человек, который ввёл пароль верно
                 с первого раза, разгадывать картинки не должен. Капча — ответ
                 на подбор, а не пошлина со всех подряд. */ ?>
        <?= field_toggle('captcha_enabled', t('Включить капчу'), setting('captcha.enabled') === '1',
              t('Появится только после нескольких неудач подряд — тем, кто вошёл сразу, она не встретится.')) ?>
        <?= field_text('captcha_after_attempts', t('Показывать после N неудач'),
              (string)(int)setting('captcha.after_attempts', 3), [
              'mask' => '##', 'inputmode' => 'numeric', 'mono' => true, 'max' => 2,
              'hint' => t('Допустимо 2 — 20. Ниже двух капча встречает человека с первой опечатки.')]) ?>
        <?= field_toggle('captcha_on_register', t('Требовать при регистрации'), setting('captcha.on_register') === '1') ?>
        <?= field_toggle('captcha_on_recover', t('Требовать при восстановлении'), setting('captcha.on_recover') === '1') ?>
        <?php /* Срок жизни картинки код спрашивал с самого начала, а задать его
                 было негде: значение по умолчанию оставалось навсегда. Пять
                 минут годятся не всем — на медленном канале капча успевает
                 протухнуть, пока грузится страница. */ ?>
        <?= field_text('captcha_ttl', t('Картинка живёт, секунд'),
              (string)Captcha::ttl(), [
              'type' => 'number', 'low' => 60, 'high' => 3600, 'mono' => true,
              'hint' => t('Допустимо 60 — 3600. Истекшую картинку человек увидит как «попробуйте ещё раз».')]) ?>

        <?php /* Своя картинка по умолчанию и осознанно: она работает в
                 установке без выхода наружу, ничего не рассказывает о
                 посетителях третьей стороне и не тянет чужой скрипт на экран
                 входа. Сторонний виджет ловит автоматику лучше, но платит и
                 тем, и другим, — поэтому это выбор владельца, а не наш. */ ?>
        <?php
        $capOptions = [];
        $capWhy = '';
        foreach (Captcha::PROVIDERS as $pk => [$ptitle, $pwhy]) {
            $capOptions[$pk] = t($ptitle);
            if (Captcha::provider() === $pk) $capWhy = t($pwhy);
        }
        echo field_select('captcha_provider', t('Чем проверять'), $capOptions, Captcha::provider(), ['hint' => $capWhy]);
        ?>
        <?php /* Ключи нужны обоим виджетам и оба публикуются провайдером
                 отдельно: site key уезжает в разметку, secret остаётся здесь.
                 Пока хоть одного нет, виджет не считается настроенным — иначе
                 он показал бы пустую рамку и запер вход всем. */ ?>
        <div class="grid-2">
          <?= field_text('captcha_site_key', t('Site key'), (string)setting('captcha.site_key', ''),
                ['mono' => true, 'autocomplete' => 'off', 'max' => 190]) ?>
          <?= field_text('captcha_secret', t('Secret'), (string)setting('captcha.secret', ''),
                ['mono' => true, 'type' => 'password', 'autocomplete' => 'off', 'max' => 190]) ?>
        </div>
        <?php if (Captcha::provider() !== 'own' && !Captcha::widgetReady()): ?>
        <?= callout(t('Виджет выбран, но ключей нет — проверка пока идёт своей картинкой.'), 'warning') ?>
        <?php endif; ?>
        <p class="hint"><?= esc(t('Если проверка у провайдера не отвечает, попытка считается непройденной: «сервис молчит, поэтому пускаем» отменяет ровно ту защиту, ради которой капчу включили.')) ?></p>
      </div>

      <div class="bento-card col-6">
        <div class="bc-title"><?= esc(t('Первый вход в сервис')) ?></div>
        <?= field_toggle('welcome_screen', t('Спрашивать при первом входе в каждый сервис'),
              Setting::get('policy.welcome_screen', '1') === '1',
              t('Человек увидит, куда он входит, и нажмёт «Продолжить»; галочка появится, только если включены документы. Отметка сохраняется у доступа. Выключено — доверенный сервис пускает молча; недоверенный спрашивает всегда, это требование протокола.')) ?>
        <p class="hint"><?= esc(t('Выключать стоит там, где все сервисы свои и вход задуман сквозным: лишний экран между кнопкой и результатом человек читает один раз, а нажимает каждый.')) ?></p>
      </div>

    </div>
    <?= form_actions() ?>
  </form>
