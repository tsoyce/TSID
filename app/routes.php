<?php
/**
 * Карта маршрутов. Флаги: api (без CSRF), auth, admin.
 * @var App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Admin\AppearanceController;
use App\Controllers\Admin\ClientsController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\HealthController;
use App\Controllers\Admin\LogsController;
use App\Controllers\Admin\RolesController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\ToolsController;
use App\Controllers\Admin\UsersController;
use App\Controllers\AuthController;
use App\Controllers\FedCmController;
use App\Controllers\RegistrationController;
use App\Controllers\CronController;
use App\Controllers\OidcController;
use App\Controllers\ProfileController;
use App\Controllers\ProfileSecurityController;
use App\Controllers\ProfileSettingsController;
use App\Controllers\PwaController;
use App\Controllers\StatusController;

/* --- Протокол OIDC (§3.1) --- */
$router->get('/.well-known/openid-configuration', [OidcController::class, 'discovery'], ['api']);
$router->get('/jwks.json', [OidcController::class, 'jwks'], ['api']);
$router->get('/authorize', [OidcController::class, 'authorize']);
$router->post('/token', [OidcController::class, 'token'], ['api']);
$router->get('/userinfo', [OidcController::class, 'userinfo'], ['api']);
$router->post('/userinfo', [OidcController::class, 'userinfo'], ['api']);
$router->post('/introspect', [OidcController::class, 'introspect'], ['api']);
$router->post('/revoke', [OidcController::class, 'revoke'], ['api']);

/* --- Установка моста (v4.22): портал приходит с билетом и забирает реквизиты
   сам. Без входа и без CSRF: обращается не браузер человека, а сервер портала,
   и предъявляет он одноразовый билет — он и есть вся авторизация. */
$router->post('/portal/bootstrap', [OidcController::class, 'portalBootstrap'], ['api']);
/* Как портал открывает вход: окном или страницей (v4.72). Читает sso.js. */
$router->get('/portal/ui', [OidcController::class, 'portalUi'], ['api']);

/* --- FedCM: нативный диалог браузера вместо редиректа (§6, v3.2) ---
   Дополняет OIDC, не заменяет: браузер без FedCM идёт через /authorize. */
$router->get('/.well-known/web-identity', [FedCmController::class, 'wellKnown'], ['api']);
$router->get('/fedcm/config.json', [FedCmController::class, 'config'], ['api']);
$router->get('/fedcm/accounts', [FedCmController::class, 'accounts'], ['api']);
$router->get('/fedcm/client-metadata', [FedCmController::class, 'clientMetadata'], ['api']);
$router->post('/fedcm/assertion', [FedCmController::class, 'assertion'], ['api']);

/* --- Аутентификация (§5.1) --- */
$router->get('/', [AuthController::class, 'home']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'doLogin']);
$router->get('/login/2fa', [AuthController::class, 'show2fa']);
$router->post('/login/2fa', [AuthController::class, 'do2fa']);
$router->post('/login/link', [AuthController::class, 'sendMagicLink']);
$router->get('/magic/{token}', [AuthController::class, 'magicLogin']);
$router->post('/magic/{token}', [AuthController::class, 'magicConfirm']);
/* Картинка капчи: своя, без третьей стороны на экране входа */
$router->get('/captcha.png', [AuthController::class, 'captchaImage'], ['api']);
$router->get('/register', [RegistrationController::class, 'showRegister']);
$router->post('/register', [RegistrationController::class, 'doRegister']);
/* Регистрация по шагам (v4.96): почта → код → имя и пароль. */
$router->post('/register/code', [RegistrationController::class, 'code']);
$router->post('/register/resend', [RegistrationController::class, 'resend']);
$router->post('/register/restart', [RegistrationController::class, 'restart']);
$router->post('/register/finish', [RegistrationController::class, 'finish']);
$router->get('/register/continue/{token}', [RegistrationController::class, 'showContinue']);
$router->post('/register/continue/{token}', [RegistrationController::class, 'continueByLink']);
/* Подтверждения по ссылке из письма: GET показывает кнопку, действие — POST.
   Сканеры ссылок ходят по GET и гасили бы одноразовый токен за человека. */
/* Почта не подтверждена: прислать письмо ещё раз или поменять адрес. Оба
   пути нужны там, где письмо не дошло, — а до v4.18.2 выхода не было вовсе. */
/* Замок: короткая переавторизация вместо пароля (v4.20). Флаг `auth` нужен —
   заперта именно ВОШЕДШАЯ сессия, а гостю тут делать нечего. */
$router->post('/profile/security/pin', [App\Controllers\ProfileSecurityController::class, 'setPin'], ['auth']);
$router->post('/profile/security/pin/clear', [App\Controllers\ProfileSecurityController::class, 'clearPin'], ['auth']);
$router->get('/lock', [App\Controllers\LockController::class, 'show'], ['auth']);
$router->post('/lock/pin', [App\Controllers\LockController::class, 'pin'], ['auth']);
$router->post('/verify/resend', [RegistrationController::class, 'resendVerify']);
$router->get('/verify/change', [RegistrationController::class, 'showChangePending']);
$router->post('/verify/change', [RegistrationController::class, 'changePending']);
/* Подтверждение почты до входа (v4.72) — выше шаблона /verify/{token}: роутер берёт первое совпадение. */
$router->get('/verify/wait', [\App\Controllers\EmailGateController::class, 'wait']);
$router->post('/verify/code', [\App\Controllers\EmailGateController::class, 'code']);
$router->post('/verify/wait/resend', [\App\Controllers\EmailGateController::class, 'resend']);
$router->post('/verify/wait/email', [\App\Controllers\EmailGateController::class, 'change']);
$router->get('/verify/{token}', [RegistrationController::class, 'showVerifyEmail']);
$router->post('/verify/{token}', [RegistrationController::class, 'verifyEmail']);
$router->get('/recover', [RegistrationController::class, 'showRecover']);
$router->post('/recover', [RegistrationController::class, 'doRecover']);
$router->get('/recover/{token}', [RegistrationController::class, 'showReset']);
$router->post('/recover/{token}', [RegistrationController::class, 'doReset']);
$router->get('/password/set/{token}', [RegistrationController::class, 'showSetPassword']);
$router->post('/password/set/{token}', [RegistrationController::class, 'doSetPassword']);
$router->post('/passkey/login/options', [App\Controllers\PasskeyController::class, 'loginOptions']);
$router->post('/passkey/login', [App\Controllers\PasskeyController::class, 'login']);
$router->post('/passkey/register/options', [App\Controllers\PasskeyController::class, 'registerOptions'], ['auth']);
$router->post('/passkey/register', [App\Controllers\PasskeyController::class, 'register'], ['auth']);
$router->post('/passkey/delete', [App\Controllers\PasskeyController::class, 'delete'], ['auth']);
$router->get('/consent', [OidcController::class, 'showConsent'], ['auth']);
$router->post('/consent', [OidcController::class, 'doConsent'], ['auth']);
$router->get('/logout', [AuthController::class, 'logoutChoice']);
$router->post('/logout', [AuthController::class, 'doLogout']);
$router->get('/goodbye', [AuthController::class, 'goodbye']);
$router->get('/delete/cancel/{token}', [AuthController::class, 'cancelDelete']);
$router->get('/email/revert/{token}', [AuthController::class, 'showRevertEmailChange']);
$router->post('/email/revert/{token}', [AuthController::class, 'revertEmailChange']);
$router->get('/setup/{token}', [AuthController::class, 'clientSetup']);
/* Возврат из Telegram переходом (v4.87): данные во фрагменте, их переносит app.js. */
$router->get('/social/telegram/return', [App\Controllers\SocialController::class, 'telegramReturn']);
$router->get('/social/{provider}/start', [App\Controllers\SocialController::class, 'start']);
$router->get('/social/{provider}/callback', [App\Controllers\SocialController::class, 'callback']);
$router->post('/social/telegram/callback', [App\Controllers\SocialController::class, 'callback'], ['api']);
$router->post('/social/unlink', [App\Controllers\SocialController::class, 'unlink'], ['auth']);
/* «Создать аккаунт?»: pending_signup кладёт только callback после OAuth,
   поэтому на экран не попасть с улицы */
$router->get('/social/signup', [App\Controllers\SocialController::class, 'signup']);
$router->post('/social/signup', [App\Controllers\SocialController::class, 'signupConfirm']);
$router->get('/social/signup/cancel', [App\Controllers\SocialController::class, 'signupCancel']);
$router->get('/social/signup/link', [App\Controllers\SocialController::class, 'signupLink']);
/* Слияние дублей: pending_merge кладёт только SocialController после OAuth */
$router->get('/profile/merge', [App\Controllers\MergeController::class, 'show'], ['auth']);
$router->post('/profile/merge', [App\Controllers\MergeController::class, 'confirm'], ['auth']);
$router->get('/profile/merge/cancel', [App\Controllers\MergeController::class, 'cancel'], ['auth']);
$router->get('/invite/{token}', [App\Controllers\InviteController::class, 'show']);
$router->post('/invite/{token}', [App\Controllers\InviteController::class, 'accept']);

/* --- Профиль (§5.1) --- */
$router->get('/profile', [ProfileController::class, 'index'], ['auth']);
$router->get('/profile/edit', [ProfileController::class, 'edit'], ['auth']);
$router->post('/profile/edit', [ProfileController::class, 'save'], ['auth']);
$router->post('/profile/emails/delete', [ProfileController::class, 'deleteEmail'], ['auth']);
$router->post('/profile/avatar', [ProfileController::class, 'uploadAvatar'], ['auth']);
$router->post('/profile/avatar/delete', [ProfileController::class, 'deleteAvatar'], ['auth']);
/* Галерея фото: то, что приехало от провайдеров, плюс загруженное вручную */
$router->post('/profile/photo/use', [ProfileController::class, 'usePhoto'], ['auth']);
$router->post('/profile/photo/delete', [ProfileController::class, 'deletePhoto'], ['auth']);
$router->get('/profile/security', [ProfileSecurityController::class, 'index'], ['auth']);
$router->post('/profile/security/password', [ProfileSecurityController::class, 'changePassword'], ['auth']);
$router->post('/profile/security/2fa/start', [ProfileSecurityController::class, 'start2fa'], ['auth']);
$router->post('/profile/security/2fa/confirm', [ProfileSecurityController::class, 'confirm2fa'], ['auth']);
$router->post('/profile/security/2fa/disable', [ProfileSecurityController::class, 'disable2fa'], ['auth']);
$router->get('/profile/security/recovery.txt', [ProfileSecurityController::class, 'downloadRecovery'], ['auth']);
$router->get('/profile/sessions', [ProfileController::class, 'sessions'], ['auth']);
$router->post('/profile/sessions/revoke', [ProfileController::class, 'revokeSession'], ['auth']);
$router->get('/profile/services', [ProfileController::class, 'services'], ['auth']);
$router->post('/profile/services/revoke', [ProfileController::class, 'revokeService'], ['auth']);
$router->get('/profile/settings', [ProfileSettingsController::class, 'index'], ['auth']);
$router->post('/profile/settings/account', [ProfileSettingsController::class, 'saveAccount'], ['auth']);
$router->post('/profile/settings/email', [ProfileSettingsController::class, 'requestEmailChange'], ['auth']);
$router->get('/email/confirm/{token}', [ProfileSettingsController::class, 'showConfirmEmailChange']);
$router->post('/email/confirm/{token}', [ProfileSettingsController::class, 'confirmEmailChange']);
$router->post('/profile/settings/notifications', [ProfileSettingsController::class, 'saveNotifications'], ['auth']);
$router->post('/profile/settings/export', [ProfileSettingsController::class, 'requestExport'], ['auth']);
$router->get('/profile/export/{token}', [ProfileSettingsController::class, 'downloadExport'], ['auth']);
$router->post('/profile/settings/delete', [ProfileSettingsController::class, 'requestDelete'], ['auth']);

/* --- Командная палитра (Ctrl+K): доступна любому вошедшему, права внутри --- */
$router->get('/lang/{code}', [AuthController::class, 'switchLang']);
$router->get('/search', [App\Controllers\Admin\SearchController::class, 'search'], ['auth']);

/* --- Аватары и иконки порталов --- */
$router->get('/avatar/{sub}/{file}', [ProfileController::class, 'serveAvatar']);
$router->get('/photo/{sub}/{file}', [ProfileController::class, 'servePhoto']);
/* Логотип портала для «мостика» — своя копия, не проксирование чужого адреса */
$router->get('/brand/{file}', [ProfileController::class, 'serveBrandLogo']);
$router->get('/client-icon/{id}', [ProfileController::class, 'serveClientIcon']);
$router->get('/branding/{file}', [ProfileController::class, 'serveBranding']);
/* Обложка панели входа — картинки, видео (v4.81); публично: нужна экрану входа */
$router->get('/cover/{file}', [ProfileController::class, 'serveCover']);

/* --- PWA: установка на домашний экран (v3.7) ---
   Манифест и иконки генерируются из брендинга, поэтому это маршруты, а не
   статика. sw.js обязан отдаваться с корня: область действия воркера не может
   быть шире каталога, из которого он отдан. */
$router->get('/manifest.webmanifest', [PwaController::class, 'manifest'], ['api']);
$router->get('/pwa/{file}', [PwaController::class, 'icon'], ['api']);
$router->get('/sw.js', [PwaController::class, 'serviceWorker'], ['api']);
$router->get('/offline', [PwaController::class, 'offline'], ['api']);

/* --- Админка (§5.2) --- */
/* Переключение контекста: где администратор сейчас работает (v4.26).
   POST, а не GET-ссылка: меняет состояние сессии, и на такое поисковый робот
   или предзагрузка браузера наткнуться не должны. */
$router->post('/admin/context', [DashboardController::class, 'context'], ['admin']);
$router->get('/admin', [DashboardController::class, 'index'], ['admin']);
$router->post('/admin/onboarding/hide', [DashboardController::class, 'hideOnboarding'], ['admin']);
$router->get('/admin/users', [UsersController::class, 'index'], ['admin']);
/* Похоже на ботов (v4.95) — до /admin/users/{id}, иначе {id} перехватит «bots». */
$router->get('/admin/users/bots', [\App\Controllers\Admin\BotsController::class, 'index'], ['admin']);
$router->post('/admin/users/bots', [\App\Controllers\Admin\BotsController::class, 'purge'], ['admin']);
$router->get('/admin/users/{id}', [UsersController::class, 'card'], ['admin']);
/* Разделы человека: у каждого свой адрес (v4.30). Карточкой делятся в
   переписке чаще прочих — «посмотри его сессии», — и вкладка такой ссылки
   не даёт. */
$router->get('/admin/users/{id}/access', [UsersController::class, 'accessSection'], ['admin']);
$router->get('/admin/users/{id}/roles', [UsersController::class, 'rolesSection'], ['admin']);
$router->get('/admin/users/{id}/sessions', [UsersController::class, 'sessions'], ['admin']);
$router->get('/admin/users/{id}/audit', [UsersController::class, 'audit'], ['admin']);
$router->get('/admin/users/{id}/activity', [UsersController::class, 'activity'], ['admin']);
$router->get('/admin/users/{id}/actions', [UsersController::class, 'actions'], ['admin']);
$router->post('/admin/users/{id}/update', [UsersController::class, 'update'], ['admin']);
$router->post('/admin/users/{id}/action', [UsersController::class, 'action'], ['admin']);
/* Опасная зона пользователя: окончательное удаление мимо 14-дневного холда. */
$router->get('/admin/users/{id}/merge', [UsersController::class, 'merge'], ['admin']);
$router->post('/admin/users/{id}/merge', [UsersController::class, 'mergeConfirm'], ['admin']);
$router->get('/admin/users/{id}/danger', [UsersController::class, 'danger'], ['admin']);
$router->post('/admin/users/{id}/danger', [UsersController::class, 'dangerUnlock'], ['admin']);
$router->post('/admin/users/{id}/access', [UsersController::class, 'accessAction'], ['admin']);
$router->post('/admin/users/{id}/roles', [UsersController::class, 'rolesAction'], ['admin']);
$router->post('/admin/impersonate/stop', [UsersController::class, 'stopImpersonate'], ['auth']);
$router->get('/admin/clients', [ClientsController::class, 'index'], ['admin']);
$router->post('/admin/clients/create', [ClientsController::class, 'create'], ['admin']);
$router->get('/admin/clients/{id}', [ClientsController::class, 'card'], ['admin']);
$router->get('/admin/clients/{id}/req', [ClientsController::class, 'req'], ['admin']);
/* Разделы сервиса: у каждого свой адрес (v4.30). */
$router->get("/admin/clients/{id}/artifacts", [ClientsController::class, "artifacts"], ["admin"]);
$router->get("/admin/clients/{id}/people", [ClientsController::class, "people"], ["admin"]);
$router->post("/admin/clients/{id}/people", [ClientsController::class, "grantAccess"], ["admin"]);
$router->get("/admin/clients/{id}/roles", [ClientsController::class, "rolesSection"], ["admin"]);
$router->get("/admin/clients/{id}/invites", [ClientsController::class, "invites"], ["admin"]);
$router->get("/admin/clients/{id}/events", [ClientsController::class, "events"], ["admin"]);
$router->get('/admin/clients/{id}/look', [ClientsController::class, 'look'], ['admin']);
$router->post('/admin/clients/{id}/look', [ClientsController::class, 'lookSave'], ['admin']);
$router->get('/admin/clients/{id}/look/preview', [ClientsController::class, 'lookPreview'], ['admin']);
$router->get("/admin/clients/{id}/history", [ClientsController::class, "history"], ["admin"]);
$router->post('/admin/clients/{id}/update', [ClientsController::class, 'update'], ['admin']);
$router->post('/admin/clients/{id}/action', [ClientsController::class, 'action'], ['admin']);
/* Опасная зона отдельной страницей и за паролем: удаление портала не должно
   попадаться на глаза тому, кто зашёл поправить redirect_uri. */
$router->get('/admin/clients/{id}/danger', [ClientsController::class, 'danger'], ['admin']);
$router->post('/admin/clients/{id}/danger', [ClientsController::class, 'dangerUnlock'], ['admin']);
$router->get('/admin/clients/{id}/integration.md', [ClientsController::class, 'integrationMd'], ['admin']);
$router->get('/admin/clients/{id}/kit.zip', [ClientsController::class, 'kitZip'], ['admin']);
/* Карта ролей: договорённость «кто есть кто» между SSO и порталом */
/* «Мост»: спросить портал о его ролях и оформлении */
$router->post('/admin/clients/{id}/manifest', [ClientsController::class, 'fetchManifest'], ['admin']);
$router->post('/admin/clients/{id}/redirect-miss', [ClientsController::class, 'redirectMiss'], ['admin']);
$router->post('/admin/clients/{id}/cover-fetch', [ClientsController::class, 'coverFetch'], ['admin']);
/* Перенос людей: ключ, предпросмотр «что будет», сам перенос */
$router->post('/admin/clients/{id}/users/key', [ClientsController::class, 'usersKey'], ['admin']);
$router->get('/admin/clients/{id}/users/preview', [ClientsController::class, 'usersPreview'], ['admin']);
$router->post('/admin/clients/{id}/users/pull', [ClientsController::class, 'usersPull'], ['admin']);
$router->post('/admin/clients/{id}/rolemap', [ClientsController::class, 'saveRoleMap'], ['admin']);
$router->get('/admin/clients/{id}/rolemap.json', [ClientsController::class, 'exportRoleMap'], ['admin']);
$router->post('/admin/clients/{id}/rolemap/import', [ClientsController::class, 'importRoleMap'], ['admin']);
$router->get('/admin/roles', [RolesController::class, 'index'], ['admin']);
$router->post('/admin/roles/create', [RolesController::class, 'create'], ['admin']);
$router->get('/admin/roles/{id}', [RolesController::class, 'card'], ['admin']);
$router->post('/admin/roles/{id}/permissions', [RolesController::class, 'savePermissions'], ['admin']);
$router->post('/admin/roles/{id}/delete', [RolesController::class, 'delete'], ['admin']);
$router->get('/admin/orgs', [App\Controllers\Admin\OrgsController::class, 'index'], ['admin']);
$router->post('/admin/orgs/create', [App\Controllers\Admin\OrgsController::class, 'create'], ['admin']);
$router->get('/admin/orgs/{id}', [App\Controllers\Admin\OrgsController::class, 'card'], ['admin']);
/* Разделы организации: у каждого свой адрес — на него можно сослаться,
   вернуться после сохранения и найти поиском. Вкладка ничего этого не даёт. */
$router->get('/admin/orgs/{id}/projects', [App\Controllers\Admin\OrgsController::class, 'projects'], ['admin']);
$router->get('/admin/orgs/{id}/people', [App\Controllers\Admin\OrgsController::class, 'people'], ['admin']);
$router->get('/admin/orgs/{id}/roles', [App\Controllers\Admin\OrgsController::class, 'roles'], ['admin']);
$router->get('/admin/orgs/{id}/access', [App\Controllers\Admin\OrgsController::class, 'access'], ['admin']);
$router->get('/admin/orgs/{id}/logs', [App\Controllers\Admin\OrgsController::class, 'logs'], ['admin']);
$router->post('/admin/orgs/{id}/action', [App\Controllers\Admin\OrgsController::class, 'action'], ['admin']);
/* Опасная зона — тот же механизм, что у порталов (App\Core\DangerZone). */
$router->get('/admin/orgs/{id}/danger', [App\Controllers\Admin\OrgsController::class, 'danger'], ['admin']);
$router->post('/admin/orgs/{id}/danger', [App\Controllers\Admin\OrgsController::class, 'dangerUnlock'], ['admin']);
$router->get('/admin/webhooks', [App\Controllers\Admin\WebhooksController::class, 'index'], ['admin']);
$router->post('/admin/webhooks/create', [App\Controllers\Admin\WebhooksController::class, 'create'], ['admin']);
$router->post('/admin/webhooks/{id}/action', [App\Controllers\Admin\WebhooksController::class, 'action'], ['admin']);
$router->get('/admin/logs', [LogsController::class, 'audit'], ['admin']);
$router->get('/admin/logs/export.csv', [LogsController::class, 'exportCsv'], ['admin']);
$router->get('/admin/logs/errors', [LogsController::class, 'errors'], ['admin']);
/* Журнал активности и страница адреса (v4.72). */
$router->get('/admin/logs/requests', [LogsController::class, 'requests'], ['admin']);
$router->get('/admin/ip/{ip}', [LogsController::class, 'ip'], ['admin']);
/* Блокировка адресов (v4.72). */
$router->get('/admin/blocks', [\App\Controllers\Admin\BlocksController::class, 'index'], ['admin']);
$router->post('/admin/blocks', [\App\Controllers\Admin\BlocksController::class, 'add'], ['admin']);
$router->post('/admin/blocks/{id}/remove', [\App\Controllers\Admin\BlocksController::class, 'remove'], ['admin']);
$router->post('/admin/blocks/bulk', [\App\Controllers\Admin\BlocksController::class, 'bulk'], ['admin']);
$router->post('/admin/blocks/remove', [\App\Controllers\Admin\BlocksController::class, 'removeMany'], ['admin']);
$router->get('/admin/logs/errors/download', [LogsController::class, 'errorsDownload'], ['admin']);
$router->post('/admin/logs/errors/purge', [LogsController::class, 'errorsPurge'], ['admin']);
$router->post('/admin/logs/mail/purge', [LogsController::class, 'mailPurge'], ['admin']);
$router->get('/admin/logs/mail', [LogsController::class, 'mail'], ['admin']);
$router->post('/admin/logs/mail/{id}/retry', [LogsController::class, 'mailRetry'], ['admin']);
$router->post('/admin/logs/mail/retry-all', [LogsController::class, 'mailRetryAll'], ['admin']);
/* Оформление сайта: главная и экран входа — то, что видит НЕ вошедший.
   Отдельно от настроек намеренно: настройки про то, как установка работает,
   а здесь про то, какой её видят посторонние (§5.4). */
$router->get('/admin/appearance', [AppearanceController::class, 'index'], ['admin']);
$router->post('/admin/appearance/save', [AppearanceController::class, 'save'], ['admin']);
$router->get('/admin/appearance/preview', [AppearanceController::class, 'preview'], ['admin']);
$router->post('/admin/appearance/fonts', [AppearanceController::class, 'fonts'], ['admin']);
$router->get('/fonts/{slug}/{file}', [ProfileController::class, 'serveFont']);
$router->get('/admin/look/palette', [AppearanceController::class, 'palette'], ['admin']);
$router->post('/admin/look/schemes', [AppearanceController::class, 'schemeSave'], ['admin']);
$router->post('/admin/look/schemes/drop', [AppearanceController::class, 'schemeDrop'], ['admin']);
$router->get('/admin/settings/requisites', [SettingsController::class, 'requisites'], ['admin']);
$router->post('/admin/settings/requisites', [SettingsController::class, 'saveRequisites'], ['admin']);
$router->get('/admin/settings', [SettingsController::class, 'index'], ['admin']);
/* Раздел настроек своим адресом: на вкладку нельзя было дать ссылку. */
$router->get('/admin/settings/section/{section}', [SettingsController::class, 'index'], ['admin']);
$router->post('/admin/settings/save', [SettingsController::class, 'save'], ['admin']);
$router->post('/admin/settings/smtp-test', [SettingsController::class, 'smtpTest'], ['admin']);
$router->post('/admin/settings/rotate-keys', [SettingsController::class, 'rotateKeys'], ['admin']);
$router->get('/admin/health', [HealthController::class, 'index'], ['admin']);
/* Разделы здоровья: у каждого свой адрес (v4.32). */
$router->get('/admin/health/errors', [HealthController::class, 'errors'], ['admin']);
$router->get('/admin/health/delivery', [HealthController::class, 'delivery'], ['admin']);
$router->get('/admin/health/probe', [HealthController::class, 'probe'], ['admin']);
$router->post('/admin/health/token', [HealthController::class, 'token'], ['admin']);
/* Системный раздел владельца (SYSTEM_LIFECYCLE.md). Не владельцу — 404:
   существование кнопки «сбросить проект» его не касается. */
$router->get('/admin/system', [App\Controllers\Admin\SystemController::class, 'index'], ['admin']);
$router->post('/admin/system/db/probe', [App\Controllers\Admin\SystemController::class, 'probeDb'], ['admin']);
$router->post('/admin/system/db/switch', [App\Controllers\Admin\SystemController::class, 'switchDb'], ['admin']);
$router->post('/admin/system/db/transfer', [App\Controllers\Admin\SystemController::class, 'transferDb'], ['admin']);
$router->post('/admin/system/domain/check', [App\Controllers\Admin\SystemController::class, 'checkDomain'], ['admin']);
$router->post('/admin/system/domain/move', [App\Controllers\Admin\SystemController::class, 'moveDomain'], ['admin']);
$router->post('/admin/system/domain/rollback', [App\Controllers\Admin\SystemController::class, 'rollbackDomain'], ['admin']);
$router->post('/admin/system/domain/remind', [App\Controllers\Admin\SystemController::class, 'remindPortals'], ['admin']);
/* Чистка помеченных на удаление пачкой (v4.18.2). Владельцу и только отсюда. */
$router->post('/admin/system/purge', [App\Controllers\Admin\SystemController::class, 'bulkPurge'], ['admin']);
$router->post('/admin/system/reset/code', [App\Controllers\Admin\SystemController::class, 'resetCode'], ['admin']);
$router->post('/admin/system/reset/run', [App\Controllers\Admin\SystemController::class, 'resetRun'], ['admin']);
/* Слово-подтверждение для проверки переезда. Отдаётся без сессии: его читает
   наш же экземпляр по новому адресу, а не браузер человека. */
$router->get('/.well-known/sso-move', [App\Controllers\Admin\SystemController::class, 'moveNonce'], ['api']);
$router->get('/.well-known/tsoy-move', [App\Controllers\Admin\SystemController::class, 'moveNonce'], ['api']); // прежний адрес, до 5.0
$router->post('/admin/system/owner/transfer', [App\Controllers\Admin\SystemController::class, 'transferOwner'], ['admin']);
$router->get('/admin/system/owner/accept/{token}', [App\Controllers\Admin\SystemController::class, 'showAcceptOwner'], ['admin']);
$router->post('/admin/system/owner/accept/{token}', [App\Controllers\Admin\SystemController::class, 'acceptOwner'], ['admin']);

$router->get('/admin/tools', [ToolsController::class, 'index'], ['admin']);
$router->post('/admin/tools/import', [ToolsController::class, 'import'], ['admin']);
$router->post('/admin/tools/migrate', [ToolsController::class, 'migrate'], ['admin']);
/* Короткий адрес для баннера: /admin/tools может не открыться, если не хватает
   таблицы именно из непринятой миграции — а починка должна работать всегда. */
$router->post('/admin/migrate', [ToolsController::class, 'migrate'], ['admin']);
$router->get('/admin/migrate', [ToolsController::class, 'migrateScreen'], ['admin']);
$router->post('/admin/tools/clear-cache', [ToolsController::class, 'clearCache'], ['admin']);
/* Чистка вручную: планировщик после установки настроен далеко не всегда */
$router->post('/admin/tools/housekeeping', [ToolsController::class, 'housekeeping'], ['admin']);
$router->post('/admin/tools/orphans', [ToolsController::class, 'orphans'], ['admin']);
$router->get('/admin/tools/backup.zip', [ToolsController::class, 'backup'], ['admin']);
/* Слепок настроек: выгрузка одним файлом и загрузка в другую установку (v4.15). */
$router->get('/admin/tools/settings.json', [ToolsController::class, 'settingsExport'], ['admin']);
$router->post('/admin/tools/settings-import', [ToolsController::class, 'settingsImport'], ['admin']);
$router->post('/admin/tools/api-token', [ToolsController::class, 'createApiToken'], ['admin']);
$router->post('/admin/tools/api-token/{id}/revoke', [ToolsController::class, 'revokeApiToken'], ['admin']);
$router->post('/admin/tools/api-tokens/purge', [ToolsController::class, 'purgeApiTokens'], ['admin']);
$router->post('/admin/tools/invite', [ToolsController::class, 'signupInvite'], ['admin']);

/* --- Admin API v1 (§ROADMAP v2.3): Bearer-токены со scope --- */
/* --- SCIM 2.0, подмножество (v3.2): для систем, которые умеют только его --- */
$router->get('/scim/v2/ServiceProviderConfig', [App\Controllers\ScimController::class, 'serviceProviderConfig'], ['api']);
$router->get('/scim/v2/Users', [App\Controllers\ScimController::class, 'usersList'], ['api']);
$router->post('/scim/v2/Users', [App\Controllers\ScimController::class, 'userCreate'], ['api']);
$router->get('/scim/v2/Users/{id}', [App\Controllers\ScimController::class, 'userGet'], ['api']);
$router->add('PUT', '/scim/v2/Users/{id}', [App\Controllers\ScimController::class, 'userReplace'], ['api']);
$router->add('PATCH', '/scim/v2/Users/{id}', [App\Controllers\ScimController::class, 'userPatch'], ['api']);
$router->add('DELETE', '/scim/v2/Users/{id}', [App\Controllers\ScimController::class, 'userDelete'], ['api']);
$router->get('/scim/v2/Groups', [App\Controllers\ScimController::class, 'groupsList'], ['api']);
$router->post('/scim/v2/Groups', [App\Controllers\ScimController::class, 'groupCreate'], ['api']);
$router->get('/scim/v2/Groups/{id}', [App\Controllers\ScimController::class, 'groupGet'], ['api']);
$router->add('PATCH', '/scim/v2/Groups/{id}', [App\Controllers\ScimController::class, 'groupPatch'], ['api']);
$router->add('DELETE', '/scim/v2/Groups/{id}', [App\Controllers\ScimController::class, 'groupDelete'], ['api']);

$router->get('/api/v1/openapi.json', [App\Controllers\ApiController::class, 'openapi'], ['api']);
$router->get('/api/v1/users', [App\Controllers\ApiController::class, 'usersList'], ['api']);
$router->post('/api/v1/users', [App\Controllers\ApiController::class, 'userCreate'], ['api']);
/* «Ху из зу»: портал спрашивает о человеке по тому, что человек знает сам.
   Объявлены ДО /users/{sub}, иначе {sub} перехватит by-number и by-email. */
$router->get('/api/v1/users/by-number/{number}', [App\Controllers\ApiController::class, 'userByNumber'], ['api']);
$router->get('/api/v1/users/by-email/{email}', [App\Controllers\ApiController::class, 'userByEmail'], ['api']);
$router->get('/api/v1/users/{sub}', [App\Controllers\ApiController::class, 'userGet'], ['api']);
$router->add('PATCH', '/api/v1/users/{sub}', [App\Controllers\ApiController::class, 'userUpdate'], ['api']);
$router->add('DELETE', '/api/v1/users/{sub}', [App\Controllers\ApiController::class, 'userDelete'], ['api']);
$router->get('/api/v1/clients', [App\Controllers\ApiController::class, 'clientsList'], ['api']);
$router->post('/api/v1/clients', [App\Controllers\ApiController::class, 'clientCreate'], ['api']);
$router->get('/api/v1/clients/{clientId}', [App\Controllers\ApiController::class, 'clientGet'], ['api']);
$router->add('PATCH', '/api/v1/clients/{clientId}', [App\Controllers\ApiController::class, 'clientUpdate'], ['api']);
$router->add('DELETE', '/api/v1/clients/{clientId}', [App\Controllers\ApiController::class, 'clientDelete'], ['api']);
$router->post('/api/v1/clients/{clientId}/secret', [App\Controllers\ApiController::class, 'clientRotateSecret'], ['api']);

/* Роли и выдачи (v4.51). Список в области отвечает ровно то же, что видит
   человек в выпадающем списке: двух правд о «какие роли уместны» нет. */
$router->get('/api/v1/roles', [App\Controllers\ApiController::class, 'rolesList'], ['api']);
$router->post('/api/v1/roles', [App\Controllers\ApiController::class, 'roleCreate'], ['api']);
$router->add('DELETE', '/api/v1/roles/{id}', [App\Controllers\ApiController::class, 'roleDelete'], ['api']);
$router->get('/api/v1/users/{sub}/roles', [App\Controllers\ApiController::class, 'userRoles'], ['api']);
$router->post('/api/v1/users/{sub}/roles', [App\Controllers\ApiController::class, 'userRoleGrant'], ['api']);
$router->add('DELETE', '/api/v1/users/{sub}/roles', [App\Controllers\ApiController::class, 'userRoleRevoke'], ['api']);

/* Организации */
$router->get('/api/v1/orgs', [App\Controllers\ApiController::class, 'orgsList'], ['api']);
$router->post('/api/v1/orgs', [App\Controllers\ApiController::class, 'orgCreate'], ['api']);
$router->add('DELETE', '/api/v1/orgs/{id}', [App\Controllers\ApiController::class, 'orgDelete'], ['api']);
$router->get('/api/v1/orgs/{id}/members', [App\Controllers\ApiController::class, 'orgMembers'], ['api']);
$router->post('/api/v1/orgs/{id}/members', [App\Controllers\ApiController::class, 'orgMemberAdd'], ['api']);
$router->add('DELETE', '/api/v1/orgs/{id}/members/{sub}', [App\Controllers\ApiController::class, 'orgMemberRemove'], ['api']);

/* Доступ к порталам и настройки */
$router->post('/api/v1/users/{sub}/access', [App\Controllers\ApiController::class, 'accessGrant'], ['api']);
$router->add('DELETE', '/api/v1/users/{sub}/access', [App\Controllers\ApiController::class, 'accessRevoke'], ['api']);
$router->get('/api/v1/settings', [App\Controllers\ApiController::class, 'settingsList'], ['api']);
$router->add('PATCH', '/api/v1/settings', [App\Controllers\ApiController::class, 'settingsUpdate'], ['api']);

$router->get('/api/v1/audit', [App\Controllers\ApiController::class, 'auditList'], ['api']);

/* --- Системные --- */
/* Страница «О сервисе»: версия движка и история релизов, ссылка — в подвале */
/* Правовые документы: публичные, без входа — их читают до регистрации */
$router->get('/legal/{key}', [App\Controllers\LegalController::class, 'show']);
$router->get('/about', [App\Controllers\AboutController::class, 'index']);
$router->get('/status', [StatusController::class, 'status'], ['api']);
/* Подробное состояние машиной — по токену, выданному владельцем. Публичный
   /status отвечает «жив ли», этот — «что именно не так» (v4.32). */
$router->get('/health.json', [HealthController::class, 'api'], ['api']);
$router->get('/metrics', [App\Controllers\MetricsController::class, 'metrics'], ['api']);
$router->get('/cron', [CronController::class, 'run'], ['api']);
