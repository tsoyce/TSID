<?php /**
 * Что ломается и как часто.
 *
 * Журнал отвечает «что случилось в этой записи»; здесь — «что у нас вообще
 * ломается». Замер боевой установки: 26 записей за сутки и ОДНО сообщение —
 * но оно роняло экран входа. Такое видно только сводкой.
 */
$rows = static function (array $list, string $empty): string {
    if ($list === []) return '<p class="muted">' . esc($empty) . '</p>';
    $out = '<div class="table-wrap"><table class="table"><thead><tr>'
        . '<th>' . esc(t('Сообщение')) . '</th><th>' . esc(t('Раз')) . '</th>'
        . '<th>' . esc(t('Последний')) . '</th><th></th></tr></thead><tbody>';
    foreach ($list as $r) {
        $cls = $r['level'] === 'error' ? 'badge-danger' : 'badge-warn';
        $out .= '<tr><td><span class="badge ' . $cls . ' mono">' . esc($r['level']) . '</span> '
            . '<span class="cell-main">' . esc(mb_substr($r['msg'], 0, 110)) . '</span></td>'
            . '<td class="hint">' . (int)$r['count'] . '</td>'
            . '<td class="hint nowrap">' . esc(fmt_ago(gmdate('Y-m-d H:i:s', (int)$r['last']))) . '</td>'
            . '<td>' . ($r['request_id'] !== ''
                ? '<a class="btn btn-ghost btn-s" href="/admin/logs/errors?incident='
                  . esc($r['request_id']) . '">' . esc(t('Открыть')) . '</a>'
                : '') . '</td></tr>';
    }
    return $out . '</tbody></table></div>';
};
?>
<div class="bento-card">
  <div class="bc-title"><?= esc(t('За сутки')) ?></div>
  <?= $rows($digest['day'], t('За сутки ошибок и предупреждений нет.')) ?>
</div>

<div class="bento-card mt-16">
  <div class="bc-title"><?= esc(t('За неделю')) ?></div>
  <?= $rows($digest['week'], t('За неделю ошибок и предупреждений нет.')) ?>
  <p class="hint mt-8"><?= esc(t('Просмотрено строк журнала: :n, файлов: :d. Одинаковые сообщения сведены: числа в идентификаторах не считаются разницей.',
      ['n' => $digest['scanned'], 'd' => $digest['days']])) ?></p>
</div>
