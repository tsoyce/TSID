  <div class="bento">
    <div class="bento-card col-8">
      <div class="bc-title"><?= esc(t('Данные')) ?></div>
      <?php if ($isGlobalAdmin): ?>
      <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/update">
        <?= csrf_field() ?>
        <div class="grid-2">
          <?= field_text('last_name', t('Фамилия'), $u['last_name'], ['max' => 120, 'id' => 'f-last_name']) ?>
          <?= field_text('first_name', t('Имя'), $u['first_name'], ['max' => 120, 'id' => 'f-first_name']) ?>
        </div>
        <?= field_text('display_name', t('Отображаемое имя'), $u['display_name'], ['max' => 120, 'id' => 'f-display_name']) ?>
        <?= field_toggle('is_verified_badge', t('Галочка «подтверждённый аккаунт» экосистемы'), (bool)$u['is_verified_badge'],
              t('Знак рядом с именем на всех порталах. Ставится вручную и означает ровно то, что вы проверили этого человека сами.')) ?>
        <?= form_actions() ?>
      </form>
      <?php else: ?>
      <div class="stack">
        <div><?= esc(t('Имя:')) ?> <?= esc(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: '—') ?></div>
      </div>
      <?php endif; ?>
    </div>
    <div class="bento-card col-4">
      <div class="bc-title"><?= esc(t('Реквизиты')) ?></div>
      <?= kv_list([
        /* Почты может не быть вовсе — так приходят люди из порталов, где
           регистрация шла логином и паролём. «Нет» и «не подтверждена» это
           разные вещи, и путать их нельзя: во втором случае человеку есть
           куда писать, в первом — некуда. */
        'Email' => $u['email']
            ? $u['email'] . ($u['email_verified_at'] ? ' ✓' : ' (' . t('не подтверждён') . ')')
            : t('нет — вход только по логину'),
        'Логин' => $u['login'] ? '@' . $u['login'] : '—',
        /* Номер выше sub: именно его назовут в обращении, а sub нужен
           при разборе с порталом. */
        'Номер аккаунта' => !empty($u['account_number'])
            ? App\Core\AccountNumber::format($u['account_number']) : '—',
        'sub' => $u['public_id'],
        'Создан' => fmt_dt($u['created_at']),
        'Последний вход' => fmt_ago($u['last_login_at']),
        'Часовой пояс' => tz_gmt_label($u['timezone']),
      ], ['sub']) ?>
    </div>

    <?php /* Роли здесь больше не выдаются (v4.68): у человека для них свой
             раздел «Роли» — со всеми областями сразу. Профиль рисовал вторую,
             урезанную копию той же выдачи. */ ?>
  </div>

