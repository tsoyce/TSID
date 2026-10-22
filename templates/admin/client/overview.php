<?php /**
 * Обзор сервиса (v4.91): как дела у портала — одним экраном.
 *
 * Раньше карточка открывалась длинной формой реквизитов, а то, что важно
 * каждый день — сколько людей, входят ли, дошли ли события, отвечает ли портал,
 * — было разбросано по пяти разделам. Здесь числа плитками (каждая ведёт в
 * свой раздел), «Как дела» — проверками со значком, «Как входят» и «Чей» —
 * справкой со ссылкой туда, где это меняется. $c, $stats, $manifestError,
 * $owner, $grantedOrgs.
 */
$base = '/admin/clients/' . (int)$c['id'];
$display = ['popup' => t('Окном поверх сайта'), 'window' => t('Отдельным окном браузера'), 'page' => t('Всей страницей')];
$lookMode = App\Core\Bridge::mode($c);
$lookLabel = ['portal' => t('Как присылает портал'), 'custom' => t('Своё'), 'off' => t('Как у сайта')][$lookMode] ?? $lookMode;
$methods = App\Core\Bridge::methodsOf($c);
$methodNames = ['password' => t('Логин и пароль'), 'magic' => t('Ссылка из письма'), 'passkey' => t('Ключ устройства (passkey)'), 'social' => t('Вход через сервисы')];
$tiles = [
    ['/people', 'users', t('Люди'), (int)$stats['people'],
        (int)$stats['week'] > 0 ? t('за неделю входили: :n', ['n' => (int)$stats['week']]) : t('за неделю никто не входил'), false],
    ['/people', 'clock', t('Последний вход'), $stats['last'] ? fmt_ago((string)$stats['last']) : '—',
        $stats['last'] ? fmt_dt((string)$stats['last']) : t('ещё никто не входил'), false],
    ['/people', 'user', t('Заявки'), (int)$stats['pending'],
        (int)$stats['pending'] > 0 ? t('ждут одобрения') : t('новых нет'), (int)$stats['pending'] > 0],
    ['/events', 'activity', t('Доставка'), (int)$stats['stuck'],
        (int)$stats['stuck'] > 0 ? t('событий не доставлено') : t('всё доставлено'), (int)$stats['stuck'] > 0],
];
$checks = [
    [(int)$c['is_active'] === 1, (int)$c['is_active'] === 1 ? t('Портал включён') : t('Портал выключен'),
        (int)$c['is_active'] === 1 ? '' : t('Не пускает никого и не обновляет токены.'), '/req'],
    [$manifestError === null,
        $manifestError === null
            ? (!empty($c['manifest_fetched_at']) ? t('Портал отвечает — рассказал о себе :ago', ['ago' => fmt_ago((string)$c['manifest_fetched_at'])])
                : t('Портал о себе ещё не рассказывал — это не ошибка, если модуль его не отдаёт'))
            : t('Портал не отвечает'),
        $manifestError !== null ? (string)$manifestError['why'] : '', '/artifacts'],
    [(int)$stats['misses'] === 0, (int)$stats['misses'] === 0 ? t('Адреса возврата сходятся') : t('Сервис просит вернуть на адрес, которого здесь нет'),
        (int)$stats['misses'] > 0 ? t('Таких адресов: :n — добавьте их или забудьте.', ['n' => (int)$stats['misses']]) : '', '/req'],
    [(int)$stats['stuck'] === 0, (int)$stats['stuck'] === 0 ? t('События доставлены') : t('Не доставлено событий: :n', ['n' => (int)$stats['stuck']]),
        (int)$stats['stuck'] > 0 ? t('Выход, блокировка и отзыв доступа не дошли до портала.') : '', '/events'],
    [(int)$stats['pending'] === 0, (int)$stats['pending'] === 0 ? t('Заявок нет') : t('Заявок ждут одобрения: :n', ['n' => (int)$stats['pending']]),
        '', '/people'],
]; ?>
<div class="bento">
  <?php foreach ($tiles as [$path, $ico, $title, $value, $sub, $warn]): ?>
  <div class="bento-card col-3 list-card stat-tile<?= $warn ? ' is-warn' : '' ?>">
    <div class="bc-title"><?= icon($ico, 14) ?> <a href="<?= esc($base . $path) ?>" class="stat-link"><?= esc($title) ?></a></div>
    <?php /* Число — крупно; слова («3 дня назад») — мельче, в одну строку. */ ?>
    <div class="kpi-value<?= is_int($value) ? '' : ' is-text' ?>"><?= esc((string)$value) ?></div>
    <div class="kpi-sub"><?= esc($sub) ?></div>
  </div>
  <?php endforeach; ?>

  <div class="bento-card col-7">
    <div class="bc-title"><?= esc(t('Как дела')) ?></div>
    <ul class="check-list health-list">
      <?php foreach ($checks as [$ok, $title, $why, $path]): ?>
      <li><?= status_icon($ok ? 'ok' : 'warn', 18) ?>
        <span class="flex-1"><?= esc($title) ?><?php if ($why !== ''): ?><div class="hint"><?= esc($why) ?></div><?php endif; ?></span>
        <?php if (!$ok): ?><a class="hint nowrap" href="<?= esc($base . $path) ?>"><?= esc(t('Открыть →')) ?></a><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="bento-card col-5">
    <div class="bc-title"><?= esc(t('Как входят')) ?></div>
    <dl class="kv-list">
      <dt><?= esc(t('Вход')) ?></dt><dd><?= esc($display[(string)($c['login_display'] ?? '')] ?? t('Как решил портал')) ?></dd>
      <dt><?= esc(t('Оформление')) ?></dt><dd><?= esc($lookLabel) ?></dd>
      <dt><?= esc(t('Способы')) ?></dt><dd><?= esc($methods === null ? t('все способы установки')
          : implode(', ', array_map(static fn(string $m): string => $methodNames[$m] ?? $m, $methods))) ?></dd>
    </dl>
    <p class="hint mt-8 mb-0"><a href="<?= esc($base . '/look') ?>"><?= esc(t('Экран входа →')) ?></a></p>
    <div class="bc-title mt-16"><?= esc(t('Чей и кому открыт')) ?></div>
    <dl class="kv-list">
      <dt><?= esc(t('Владелец')) ?></dt><dd><?php if ($owner): ?><a href="/admin/orgs/<?= (int)$owner['id'] ?>"><?= esc($owner['name']) ?></a><?php else: ?><?= esc(t('ничей (уровень системы)')) ?><?php endif; ?></dd>
      <dt><?= esc(t('Кого пускать')) ?></dt><dd><?= esc(App\Models\Client::policyLabel((string)$c['access_policy'])) ?></dd>
      <dt><?= esc(t('Организациям')) ?></dt><dd><?= $grantedOrgs === [] ? esc(t('не выдан'))
          : esc(implode(', ', array_map(static fn(array $o): string => (string)$o['name'], $grantedOrgs))) ?></dd>
      <dt><?= esc(t('Своих ролей')) ?></dt><dd><a href="<?= esc($base . '/roles') ?>"><?= (int)$stats['roles'] ?></a></dd>
    </dl>
  </div>
</div>
