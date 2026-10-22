<?php /**
 * Роли человека в одной области — плашки и выдача (v4.50).
 *
 * Механизм родился в списке людей сервиса и оказался нужен всюду, где виден
 * человек: в карточке человека, в составе организации, в общем списке. Пока он
 * жил разметкой внутри одного шаблона, «всюду» означало «скопировать» — а
 * скопированное расходится: в одном месте появится крестик, в другом нет, в
 * третьем список останется старым, со всеми ролями подряд.
 *
 * Поэтому кусок один, и решает он ровно одно: показать выданное и дать выдать
 * ещё. Что именно можно выдать здесь — не его забота, это знает RoleOffer.
 *
 * $userId    — кому
 * $userName  — как зовут (для подписей органов; сам текст не рисуется)
 * $granted   — что уже выдано в этой области: строки с role_id, slug, name
 * $offer     — что можно выдать (RoleOffer::inScope)
 * $actionUrl — куда слать выдачу и отзыв
 * $mayGrant  — есть ли право менять
 * $revokeAsk — вопрос при снятии
 */ ?>
<div class="row gap-4">
  <?php foreach ($granted as $r): ?>
  <form method="post" action="<?= esc($actionUrl) ?>" class="row gap-4"
        data-confirm="<?= esc($revokeAsk) ?>">
    <?= csrf_field() ?><?= back_field() ?>
    <input type="hidden" name="action" value="role_revoke">
    <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
    <input type="hidden" name="role_id" value="<?= (int)$r['role_id'] ?>">
    <?php /* Название, а не слаг (v4.67): «client_admin» моноширинным на плашке
             читается как служебная метка, а не как «Админ портала». Слаг — в
             подсказке: он нужен тому, кто сверяет карту ролей. */ ?>
    <span class="badge badge-ink" title="<?= esc($r['slug']) ?>"><?= esc(($r['name'] ?? '') !== '' ? $r['name'] : $r['slug']) ?><?php if ($mayGrant): ?>
      <button class="badge-x" type="submit" title="<?= esc(t('Снять роль')) ?>">×</button>
    <?php endif; ?></span>
  </form>
  <?php endforeach; ?>

  <?php if ($granted === [] && !($mayGrant && $offer !== [])): ?>
  <span class="muted">—</span>
  <?php endif; ?>

  <?php /* Список прячется под кнопку, а не стоит в каждой строке.
           Замер: развёрнутая пара «список + кнопка» просит 213px, а ячейке
           после отступов достаётся 186 — в каждой строке она переносилась на
           второй этаж и отжимала ширину у соседнего столбца.

           Всплывающее окно, а не раскрытие внутри ячейки (v4.70). <details>
           раскрывался в самой строке: она вырастала вдвое, список и кнопка
           вставали столбиком, соседние строки уезжали вниз — замечание
           владельца на экране организации. Окно Popover API живёт в верхнем
           слое: его не обрезает прокрутка таблицы, и закрывается оно само —
           Esc или щелчком мимо. Место подбирает app.js; без скрипта окно
           откроется по центру экрана и всё равно сработает. */ ?>
  <?php if ($mayGrant && $offer !== []): $popId = 'role-add-' . (int)$userId . '-' . substr(md5($actionUrl), 0, 6); ?>
  <button class="btn-icon btn-icon-s" type="button" popovertarget="<?= $popId ?>"
          title="<?= esc(t('Выдать роль: :who', ['who' => $userName])) ?>"
          aria-label="<?= esc(t('Выдать роль: :who', ['who' => $userName])) ?>"><?= icon('plus', 16) ?></button>
  <div class="role-add" id="<?= $popId ?>" popover>
    <form method="post" action="<?= esc($actionUrl) ?>" class="row gap-4">
      <?= csrf_field() ?><?= back_field() ?>
      <input type="hidden" name="action" value="role_grant">
      <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
      <select class="select select-s" name="role_id"
              aria-label="<?= esc(t('Роль для :who', ['who' => $userName])) ?>">
        <?php foreach ($offer as $r): ?>
        <option value="<?= (int)$r['id'] ?>"><?= esc((($r['name'] ?? '') !== '' ? $r['name'] . ' · ' : '') . $r['slug']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-s btn-primary" type="submit"><?= esc(t('Выдать')) ?></button>
    </form>
  </div>
  <?php endif; ?>
</div>
