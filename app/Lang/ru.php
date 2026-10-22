<?php
/**
 * Русский словарь. Базовый: то, чего нет в других языках, показывается отсюда.
 * Ключи — точками по смыслу: экран.элемент.
 */

declare(strict_types=1);

return [
    /* --- Общее --- */
    'common.save' => 'Сохранить изменения',
    'common.cancel' => 'Отмена',
    'common.delete' => 'Удалить',
    'common.back' => 'Назад',
    'common.next' => 'Далее',
    'common.copy' => 'Копировать',
    'common.copied' => 'Скопировано',
    'common.show' => 'показать',
    'common.hide' => 'скрыть',
    'common.email' => 'Email',
    'common.password' => 'Пароль',
    'common.login_field' => 'Логин',
    'common.optional' => 'необязательно',
    'common.theme' => 'Тема: авто',
    'common.language' => 'Язык',
    'common.never' => '—',
    'common.footer_tagline' => 'единая учётная запись экосистемы',

    /* --- Вход --- */
    'login.title' => 'Вход',
    'login.identity' => 'Логин или email',
    'login.remember' => 'Запомнить меня',
    'login.forgot' => 'Забыли пароль?',
    'login.submit' => 'Войти',
    'login.or' => 'или',
    'login.passkey' => 'Войти по Passkey',
    'login.magic_label' => 'Вход по ссылке из письма',
    'login.magic_send' => 'Отправить',
    'login.no_account' => 'Нет аккаунта?',
    'login.create' => 'Создать',
    'login.context' => 'Вы входите в',
    'login.failed' => 'Неверный логин или пароль.',
    'login.locked' => 'Слишком много неудачных попыток. Подождите 15 минут или сбросьте пароль.',
    'login.magic_sent' => 'Если такой email зарегистрирован — письмо со ссылкой уже в пути.',

    /* --- Второй фактор --- */
    '2fa.title' => 'Подтверждение входа',
    '2fa.hint' => 'Введите 6-значный код из приложения-аутентификатора.',
    '2fa.submit' => 'Подтвердить',
    '2fa.use_recovery' => 'Использовать код восстановления',
    '2fa.recovery_hint' => 'Введите один из одноразовых кодов восстановления.',
    '2fa.back_to_code' => 'Вернуться к коду из приложения',
    '2fa.wrong' => 'Код не подошёл. Попробуйте ещё раз.',
    '2fa.too_many' => 'Код не подошёл 5 раз. Войдите заново.',

    /* --- Регистрация --- */
    'register.title' => 'Регистрация',
    'register.first_name' => 'Имя',
    'register.last_name' => 'Фамилия',
    'register.submit' => 'Создать аккаунт',
    'register.agreement' => 'Создавая аккаунт, вы принимаете политику обработки персональных данных.',
    'register.have_account' => 'Уже есть аккаунт?',
    'register.sign_in' => 'Войти',
    'register.context' => 'Аккаунт для входа в',
    'register.err_name' => 'Как к вам обращаться?',
    'register.err_email' => 'Похоже, в адресе опечатка.',
    'register.created' => 'Аккаунт создан. Мы отправили письмо — подтвердите почту.',

    /* --- Восстановление --- */
    'recover.title' => 'Восстановление доступа',
    'recover.hint' => 'Укажите email — пришлём ссылку для сброса пароля.',
    'recover.submit' => 'Отправить ссылку',
    'recover.back' => 'Вернуться ко входу',
    'recover.sent' => 'Если email существует — письмо отправлено.',
    'reset.title' => 'Новый пароль',
    'reset.new_password' => 'Новый пароль',
    'reset.submit' => 'Сохранить пароль',
    'reset.sessions_note' => 'После сохранения все активные сессии будут завершены.',
    'reset.done' => 'Пароль обновлён. Войдите с новым паролем.',
    'reset.expired' => 'Ссылка устарела или уже использована. Запросите новую.',

    /* --- Выход --- */
    'logout.title' => 'Выход',
    'logout.question' => 'Завершить только эту сессию или выйти на всех устройствах и сервисах?',
    'logout.here' => 'Выйти только здесь',
    'logout.everywhere' => 'Выйти везде',
    'logout.done' => 'Вы вышли.',
    'goodbye.title' => 'Аккаунт помечен на удаление',
    'goodbye.text' => 'Всё будет окончательно удалено через 14 дней. Передумаете — в письме, которое мы отправили, есть кнопка отмены.',

    /* --- Профиль --- */
    'profile.title' => 'Профиль',
    'profile.edit' => 'Редактировать',
    'profile.id_card' => 'Удостоверение',
    'profile.name' => 'Имя',
    'profile.birthdate' => 'Дата рождения',
    'profile.member_since' => 'В системе с',
    'profile.verified' => 'подтверждён',
    'profile.not_verified' => 'не подтверждён',
    'profile.security' => 'Безопасность',
    'profile.services' => 'Сервисы',
    'profile.devices' => 'Устройства',
    'profile.last_logins' => 'Последние входы',
    'profile.settings' => 'Настройки',
    'profile.saved' => 'Изменения сохранены.',

    /* --- Согласие и доступ --- */
    'consent.request_from' => 'Запрос от',
    'consent.will_receive' => 'Сервис получит:',
    'consent.allow' => 'Разрешить',
    'consent.note' => 'Пароль сервису не передаётся — только перечисленные данные.',
    'gate.pending_title' => 'Заявка на рассмотрении',
    'gate.blocked_title' => 'Доступ закрыт',
    'gate.invite_title' => 'Вход по приглашениям',

    /* --- Приглашение --- */
    'invite.title' => 'Приглашение в',
    'invite.accept' => 'Принять приглашение',
    'invite.login_and_accept' => 'Войти и принять',
    'invite.signed_as' => 'Вы вошли как',
    'invite.no_account' => 'Нет аккаунта?',
    'invite.create_fast' => 'Создать за полминуты',

    /* --- Ошибки --- */
    'error.400' => 'Некорректный запрос',
    'error.403' => 'Доступ закрыт',
    'error.404' => 'Страница не найдена',
    'error.419' => 'Форма устарела',
    'error.429' => 'Слишком часто',
    'error.500' => 'Что-то сломалось',
    'error.home' => 'На главную',

    /* --- Письма --- */
    'email.hello' => 'Здравствуйте',
    'email.auto_note' => 'Это письмо отправлено автоматически — отвечать на него не нужно.',
    'email.verify_subject' => 'Подтвердите почту',
    'email.verify_action' => 'Подтвердить почту',
    'email.reset_subject' => 'Сброс пароля',
    'email.reset_action' => 'Задать новый пароль',
    'email.magic_action' => 'Войти',
];
