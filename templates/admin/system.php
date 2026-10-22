<?php /**
 * /admin/system — раздел владельца (v4.2). $db, $owner, $candidates, $probe.
 * Замысел целиком — SYSTEM_LIFECYCLE.md.
 */
use App\Core\Housekeeping; ?>
<?php /* Предупреждение, а не второй подзаголовок. Оболочка уже печатает подпись
         раздела, и обычный серый абзац под ней читался как её продолжение —
         два одинаковых с виду пояснения подряд. А это не пояснение: это
         единственное место в проекте, где кнопка «назад» не спасает. */ ?>
<?= callout(t('Раздел владельца инсталляции. Операции здесь не отменяются кнопкой «назад».'), 'warning') ?>

<div class="bento">
  <?php /* Два блока со сведениями стоят рядом, а не друг под другом. Раньше все
           пять карточек раздела были во всю ширину: одинаковые плиты одна под
           другой на два с половиной экрана, где взгляду не за что зацепиться.
           Рядом идут именно те, что читают, — база и владелец. Действия ниже
           остаются во всю ширину: у них широкие формы. */ ?>
  <div class="bento-card col-7" id="db">
    <div class="bc-title"><?= esc(t('База данных')) ?></div>
    <?php if (!$db['ok']): ?>
      <?= callout(t('База не отвечает: ') . esc((string)$db['error']), 'danger') ?>
    <?php endif; ?>
    <?= kv_list([
      'Подключение' => trim(($db['version'] ?? $db['driver']) . ' · '
        . ($db['driver'] === 'sqlite'
            ? $db['name']
            : $db['host'] . ':' . $db['port'] . ' / ' . $db['name'])),
      'Префикс таблиц' => $db['prefix'] !== '' ? $db['prefix'] : '—',
      'Таблиц' => (string)$db['tables'],
      'Размер' => $db['size'] !== null ? Housekeeping::human((int)$db['size']) : '—',
      /* «0 мс» читается как заглушка, хотя для SQLite это правда: файл рядом.
         Ниже десятой доли миллисекунды говорим словами. */
      'Отклик' => $db['ping_ms'] === null ? '—'
        : ($db['ping_ms'] < 0.1 ? t('меньше 0,1 мс') : $db['ping_ms'] . ' ' . t('мс')),
    ]) ?>
    <?php if ($db['rows'] !== []): ?>
    <div class="bc-title mt-16"><?= esc(t('Строк в таблицах')) ?></div>
    <div class="stack">
      <?php foreach ($db['rows'] as $t => $n): ?>
      <div class="row-between"><span class="mono"><?= esc($t) ?></span><span><?= number_format((int)$n, 0, '.', ' ') ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="bento-card col-5">
    <div class="bc-title"><?= esc(t('Владелец инсталляции')) ?></div>
    <p class="muted"><?= esc(t('Суперадминов может быть много: роль выдаётся в два клика, чтобы человек завёл портал. Владелец один, и только ему доступен этот раздел.')) ?></p>
    <?= kv_list([
      'Владелец сейчас' => $owner ? user_title($owner) : '—',
    ]) ?>
    <?php if (count($candidates) > 1): ?>
    <form method="post" action="/admin/system/owner/transfer" class="inline-field mt-16">
      <?= csrf_field() ?>
      <select class="select" name="user_id" aria-label="<?= esc(t('Кому передать')) ?>">
        <?php foreach ($candidates as $c): if ($owner && (int)$c['id'] === (int)$owner['id']) continue; ?>
        <option value="<?= (int)$c['id'] ?>"><?= esc(user_title($c)) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary" type="submit"><?= esc(t('Передать владение')) ?></button>
    </form>
    <p class="hint mt-8"><?= esc(t('Владение переходит только после подтверждения получателем по ссылке из письма: отдать проект человеку, который в отпуске, не получится.')) ?></p>
    <?php else: ?>
    <p class="hint mt-8"><?= esc(t('Передавать некому: суперадмин в системе один.')) ?></p>
    <?php endif; ?>
  </div>

  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Проверить другое подключение')) ?></div>
    <p class="muted"><?= esc(t('Проверка ничего не переключает: SSO подключается вторым соединением, спрашивает и отключается. Сначала убедитесь, что новая база отвечает и схема на месте, — и только потом переключайтесь.')) ?></p>

    <?php if ($probe): ?>
    <div class="mb-16">
      <div class="hint mono mb-8"><?= esc($probe['target']) ?></div>
      <div class="stack">
        <?php foreach ($probe['checks'] as $c): ?>
        <div class="row-between">
          <span><?= status_icon($c['ok'] ? 'ok' : 'fail', 15) ?> <?= esc($c['what']) ?></span>
          <?php /* Ответ драйвера у провалившейся проверки — машинный текст,
                   показанный намеренно: это и есть ответ на вопрос «почему не
                   подключается». Оформлен цитатой, как код: прозвон отличает
                   цитату от утечки, а человек видит, что это слова драйвера. */ ?>
          <span class="hint"><?php if ($c['ok']): ?><?= esc($c['detail']) ?><?php else: ?><code><?= esc($c['detail']) ?></code><?php endif; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <form method="post" action="/admin/system/db/probe">
      <?= csrf_field() ?>
      <div class="grid-3">
        <?= field_select('driver', t('Драйвер'), ['mysql' => 'MySQL / MariaDB', 'sqlite' => 'SQLite'], 'mysql', ['id' => 'f-db-driver']) ?>
        <?= field_text('host', t('Хост'), 'localhost', ['id' => 'f-db-host']) ?>
        <?= field_text('port', t('Порт'), '3306', ['id' => 'f-db-port', 'type' => 'number', 'low' => 1, 'high' => 65535]) ?>
        <?= field_text('name', t('База'), '', ['id' => 'f-db-name', 'hint' => t('Для SQLite — путь к файлу')]) ?>
        <?= field_text('user', t('Пользователь'), '', ['id' => 'f-db-user', 'autocomplete' => 'off']) ?>
        <?= field_text('pass', t('Пароль'), '', ['id' => 'f-db-pass', 'type' => 'password', 'autocomplete' => 'new-password']) ?>
      </div>
      <?= field_text('prefix', t('Префикс таблиц'), $db['prefix'], ['id' => 'f-db-prefix', 'mono' => true]) ?>
      <?php /* Две кнопки на одной форме: реквизиты вводятся один раз. Иначе
               человек, убедившийся в проверке, набирал бы всё заново — и как
               раз тогда и ошибся бы. */ ?>
      <div class="row">
        <button class="btn btn-secondary" type="submit"><?= esc(t('Проверить')) ?></button>
        <button class="btn btn-danger-ghost" type="submit"
                formaction="/admin/system/db/switch"
                data-confirm="<?= esc(t('Переключиться на эту базу? Приложение начнёт работать с ней немедленно.')) ?>">
          <?= esc(t('Переключиться')) ?></button>
        <button class="btn btn-danger-ghost" type="submit"
                formaction="/admin/system/db/transfer"
                data-confirm="<?= esc(t('Перенести данные в эту базу и переключиться? На время переноса сайт закроется на обслуживание.')) ?>">
          <?= esc(t('Перенести данные')) ?></button>
      </div>
      <p class="hint mt-8"><?= esc(t('Переключение проходит только когда все проверки зелёные. Прежний конфиг сохраняется копией рядом.')) ?></p>
      <p class="hint"><?= esc(t('«Перенести данные» создаёт схему миграциями и копирует строки — работает и между MySQL и SQLite. Больше потолка — только дампом вручную: оборвавшийся перенос оставляет полбазы.')) ?></p>
      <?php /* Потолок код спрашивал, а задать его было негде: 200 МБ навсегда.
               База в 300 МБ — не редкость, и упереться в предел, который нельзя
               подвинуть, значит идти в консоль там, где кнопка уже есть. */ ?>
      <?= field_text('transfer_limit_mb', t('Потолок переноса, МБ'), (string)\App\Core\DbTransfer::limitMb(), [
            'type' => 'number', 'low' => 1, 'high' => 4096, 'mono' => true, 'class' => 'mw-xs',
            'hint' => t('Перенос идёт в один заход и держит базу закрытой всё это время. Потолок — про то, сколько вы готовы ждать.')]) ?>
      <p class="hint"><?= esc(t('Реквизиты нигде не сохраняются: живой конфиг один, и он в файле. Пароль от второй базы, лежащий «на всякий случай» в настройках, — это утечка.')) ?></p>
    </form>
  </div>

  <div class="bento-card col-12" id="domain">
    <div class="bc-title"><?= esc(t('Домен инсталляции')) ?></div>

    <?php if ($moved): ?>
    <?= callout(t('Домен сменён :h ч назад. Прежний адрес: ', ['h' => $moved['hours']]) . $moved['prev'], 'warning') ?>
    <?php /* Статуса «портал подтвердил» здесь нет намеренно: ломается проверка
             iss на чужой стороне, и увидеть это мы не можем. Показываем то, что
             знаем: кому ушло письмо и кому его слать было некому. */ ?>
    <?php if ($consequences['clients'] !== []): ?>
    <div class="stack mb-16">
      <?php foreach ($consequences['clients'] as $c): ?>
      <div class="row-between">
        <span><?= esc($c['name']) ?> <span class="hint mono"><?= esc(url_label((string)$c['base_url'])) ?></span></span>
        <span class="hint"><?= $c['admins'] === []
          ? esc(t('связаться некому'))
          : esc(implode(', ', $c['admins'])) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="row mb-16">
      <form method="post" action="/admin/system/domain/rollback"
            data-confirm="<?= esc(t('Вернуть прежний адрес? Passkey это не восстановит.')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-secondary" type="submit"><?= esc(t('Вернуть прежний адрес')) ?></button>
      </form>
      <form method="post" action="/admin/system/domain/remind">
        <?= csrf_field() ?>
        <button class="btn btn-secondary" type="submit"><?= esc(t('Напомнить письмом ещё раз')) ?></button>
      </form>
    </div>
    <?php endif; ?>

    <?= kv_list(['Сейчас' => $issuer]) ?>

    <?php /* Последствия — числами из базы. Экран, который пишет «возможно,
             что-то перестанет работать», не даёт принять решение. */ ?>
    <div class="bc-title mt-16"><?= esc(t('Что перестанет работать при смене')) ?></div>
    <div class="stack mb-16">
      <div class="row-between"><span><?= esc(t('Passkey (привязаны к домену, перенести нельзя)')) ?></span>
        <span><?= (int)$consequences['passkeys'] ?></span></div>
      <?php if ($consequences['passkey_only'] !== []): ?>
      <div class="row-between"><span class="text-danger"><?= esc(t('из них — единственный способ входа у:')) ?>
        <?= esc(implode(', ', $consequences['passkey_only'])) ?></span>
        <span><?= count($consequences['passkey_only']) ?></span></div>
      <?php endif; ?>
      <div class="row-between"><span><?= esc(t('Порталы: нужно поменять issuer в их конфиге')) ?></span>
        <span><?= count($consequences['clients']) ?></span></div>
      <?php if ($consequences['clients_no_admin'] !== []): ?>
      <div class="row-between"><span class="text-danger"><?= esc(t('из них не с кем связаться (нет админа портала):')) ?>
        <?= esc(implode(', ', array_column($consequences['clients_no_admin'], 'name'))) ?></span>
        <span><?= count($consequences['clients_no_admin']) ?></span></div>
      <?php endif; ?>
      <div class="row-between"><span><?= esc(t('Неиспользованные ссылки из писем')) ?></span>
        <span><?= (int)$consequences['links'] ?></span></div>
      <div class="row-between"><span><?= esc(t('Активные сессии — все завершатся')) ?></span>
        <span><?= (int)$consequences['sessions'] ?></span></div>
    </div>

    <?php if ($move): ?>
    <div class="mb-16">
      <div class="hint mono mb-8"><?= esc($move['url']) ?></div>
      <div class="row-between">
        <span><?= status_icon($move['check']['ok'] ? 'ok' : 'fail', 15) ?>
          <?php if ($move['check']['ok']): ?><?= esc($move['check']['detail']) ?><?php else: ?><code><?= esc($move['check']['detail']) ?></code><?php endif; ?></span>
      </div>
      <?php if (!$move['check']['ok']): ?>
      <p class="hint mt-8"><?= esc(t('Новый адрес должен уже вести на этот же экземпляр. Настройте DNS и веб-сервер, затем проверьте снова — по адресу ниже должно отдаваться слово:')) ?></p>
      <?= field_copy('move-probe', '', rtrim($move['url'], '/') . '/.well-known/sso-move', ['class' => 'mt-8']) ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="post" action="/admin/system/domain/check">
      <?= csrf_field() ?>
      <?= field_text('url', t('Новый адрес'), $move['url'] ?? '', [
            'id' => 'f-newurl', 'mono' => true, 'type' => 'url',
            'placeholder' => 'https://id.example.ru']) ?>
      <div class="row">
        <button class="btn btn-secondary" type="submit"><?= esc(t('Проверить адрес')) ?></button>
        <?php if ($move && $move['check']['ok']): ?>
        <button class="btn btn-danger" type="submit" formaction="/admin/system/domain/move"
                data-confirm="<?= esc(t('Сменить домен? Passkey перестанут работать безвозвратно, все сессии завершатся.')) ?>">
          <?= esc(t('Сменить домен')) ?></button>
        <?php endif; ?>
      </div>
      <p class="hint mt-8"><?= esc(t('Кнопка смены появляется только после успешной проверки: вписать адрес, которого ещё нет, значит потерять доступ к этой странице.')) ?></p>
      <p class="hint"><?= esc(t('Администраторам порталов уйдут письма, порталам — вебхук issuer.changed. Вернуть прежний адрес можно в течение суток.')) ?></p>
    </form>
  </div>

  <?php /* Чистка помеченных — между обычной опасной зоной и сбросом. Сброс
           убирает всех; здесь только тех, кого уже решили удалить и кто отлежал
           срок «передумать». */ ?>
  <div class="bento-card col-12" id="purge">
    <div class="bc-title"><?= esc(t('Чистка помеченных на удаление')) ?></div>
    <?php
    $purgePlan = \App\Core\BulkPurge::plan();
    $purgeMarked = \App\Core\BulkPurge::markedTotal();
    ?>
    <p class="muted"><?= esc(t('Удаление аккаунта отложенное: две недели человек может передумать. Отлежавших срок приходилось убирать по одному — зона, пароль, набранное имя, и так сто раз. Здесь они убираются пачкой, но список показывается до, а не после.')) ?></p>

    <?php if ($purgeMarked === 0): ?>
      <?= callout(t('Помеченных на удаление нет.'), 'info') ?>
    <?php else: ?>
    <?= kv_list([
      'Помечено всего' => (string)$purgeMarked,
      'Отлежали срок и попадут в чистку' => count($purgePlan) . ' '
          . t('(за один заход не больше :n)', ['n' => \App\Core\BulkPurge::CHUNK]),
      'Срок «передумать»' => \App\Core\BulkPurge::HOLD_DAYS . ' ' . t('дней'),
    ]) ?>

    <?php if ($purgePlan === []): ?>
      <p class="hint"><?= esc(t('Все помеченные ещё в сроке — удалять пока нечего. Каждого можно убрать поштучно в его опасной зоне, если ждать незачем.')) ?></p>
    <?php else: ?>
    <div class="table-wrap mt-16 mb-16">
      <table class="table">
        <thead><tr><th><?= esc(t('Кто')) ?></th><th><?= esc(t('sub')) ?></th><th><?= esc(t('Помечен')) ?></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($purgePlan, 0, 25) as $pu): ?>
        <tr>
          <td class="cell-main"><?= esc(user_title($pu)) ?></td>
          <td class="hint mono"><?= esc($pu['public_id']) ?></td>
          <td class="hint nowrap"><?= esc(fmt_ago($pu['deleted_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($purgePlan) > 25): ?>
    <p class="hint"><?= esc(t('и ещё :n', ['n' => count($purgePlan) - 25])) ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/system/purge"
          data-confirm="<?= esc(t('Удалить :n безвозвратно? Отменить будет нечем.', ['n' => count($purgePlan)])) ?>">
      <?= csrf_field() ?>
      <?= field_confirm_name(t('Наберите «удалить», чтобы подтвердить'), t('удалить')) ?>
      <button class="btn btn-danger" type="submit">
        <?= esc(t('Удалить :n :word', [
            'n' => count($purgePlan),
            'word' => plural(count($purgePlan), 'аккаунт', 'аккаунта', 'аккаунтов')])) ?>
      </button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="bento-card col-12" id="reset">
    <div class="bc-title"><?= esc(t('Сброс')) ?></div>
    <p class="muted"><?= esc(t('Отменить нельзя. Ровно поэтому здесь четыре условия, а не одна галочка «я понимаю».')) ?></p>

    <?php /* Свежесть бэкапа — не галочка «я сделал», а факт скачивания. */ ?>
    <?php if (!$backupFresh): ?>
      <?= callout(t('Сначала скачайте бэкап: кнопки сброса отпираются им.'), 'warning') ?>
      <a class="btn btn-secondary" href="/admin/tools/backup.zip"><?= esc(t('Скачать бэкап')) ?></a>
      <p class="hint mt-8"><?= $backupAt
        ? esc(t('Последний бэкап: :when — это дольше часа назад.', ['when' => fmt_dt($backupAt)]))
        : esc(t('Бэкап ещё не скачивали.')) ?></p>
    <?php else: ?>
      <?= callout(t('Бэкап скачан :when — можно продолжать.', ['when' => fmt_dt((string)$backupAt)]), 'success') ?>

      <div class="grid-2 mt-16">
        <?php foreach ([
          'data' => [t('Сбросить данные'), t('Уйдут люди, сессии, аудит, согласия и фото. Останутся порталы, роли, настройки, документы и ключи.')],
          'project' => [t('Сбросить проект'), t('Уйдёт всё, кроме файла конфигурации. Ключи подписи, APP_KEY и cron-токен выпускаются заново, порталы придётся подключать с нуля.')],
        ] as $level => [$title, $descr]): ?>
        <div class="bento-card surface-2">
          <div class="bc-title"><?= esc($title) ?></div>
          <p class="hint"><?= esc($descr) ?></p>
          <?php if ($codeFor !== $level): ?>
          <form method="post" action="/admin/system/reset/code">
            <?= csrf_field() ?><input type="hidden" name="level" value="<?= esc($level) ?>">
            <button class="btn btn-secondary btn-block" type="submit"><?= esc(t('Прислать код на почту')) ?></button>
          </form>
          <?php else: ?>
          <form method="post" action="/admin/system/reset/run"
                data-confirm="<?= esc(t('Это необратимо. Продолжить?')) ?>">
            <?= csrf_field() ?><input type="hidden" name="level" value="<?= esc($level) ?>">
            <?= field_text('code', t('Код из письма'), '', [
                  'id' => 'f-code-' . $level, 'mono' => true,
                  'inputmode' => 'numeric', 'autocomplete' => 'off']) ?>
            <?= field_text('confirm', t('Наберите адрес инсталляции'), '', [
                  'id' => 'f-conf-' . $level, 'mono' => true, 'autocomplete' => 'off',
                  'note' => (string)parse_url($issuer, PHP_URL_HOST)]) ?>
            <button class="btn btn-danger btn-block" type="submit"><?= esc($title) ?></button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="hint mt-16"><?= esc(t('Сброс доступен только владельцу и только отсюда: ни Admin API, ни командной строкой. Сброс по HTTP с токеном — это одна утёкшая строка в логе CI.')) ?></p>
  </div>
</div>
