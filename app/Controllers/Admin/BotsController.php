<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\BotSignal;
use App\Core\BulkPurge;
use App\Core\Flash;
use App\Core\FraudArchive;
use App\Core\IpBlock;
use App\Core\View;

/**
 * /admin/users/bots — «Похоже на ботов» (v4.95).
 *
 * Живой случай 23.10: шестьдесят четыре аккаунта ботов, а удалить их можно
 * было только по одному — карточка, опасная зона, пароль, имя. Здесь — список,
 * который собирает сама система, галочки и одно нажатие.
 *
 * Пароль не спрашиваем: удалить отсюда можно только того, кто сам завёл
 * аккаунт, не подтвердил почту и ни разу не пришёл ни в один портал, — терять
 * у такого аккаунта нечего. Список при удалении строится заново тем же кодом,
 * что показывал экран: подставить в форму живого человека не выйдет.
 */
final class BotsController extends AdminBase
{
    public function index(): never
    {
        $this->requirePerm('users.delete');
        $this->requireGlobalAdmin();
        View::render('admin/bots', [
            'pageTitle' => t('Похоже на ботов'),
            'suspects' => BotSignal::suspects(),
            'refused' => BotSignal::refusedToday(),
        ]);
    }

    public function purge(): never
    {
        $this->requirePerm('users.delete');
        $this->requireGlobalAdmin();
        $want = array_map('intval', (array)($_POST['ids'] ?? []));
        $pick = array_values(array_filter(BotSignal::suspects(),
            static fn(array $s): bool => in_array((int)$s['id'], $want, true)));
        if ($pick === []) {
            Flash::info(t('Никто не выбран — никого не тронули.'));
            redirect('/admin/users/bots');
        }
        $nets = array_values(array_unique(array_filter(array_column($pick, 'net'))));
        /* Образцы — до удаления (v4.98): журнал активности уходит вместе с
           аккаунтом, а без образца удалённый бот неотличим от человека. */
        $kept = FraudArchive::keep($pick);
        $done = BulkPurge::purge($pick, 'bots');
        $msg = t('Удалено :n :word.', ['n' => $done['users'],
            'word' => plural($done['users'], 'аккаунт', 'аккаунта', 'аккаунтов')]);
        if ($kept > 0) $msg .= ' ' . t('В архив антифрода — образцов: :n.', ['n' => $kept]);
        if (($_POST['block'] ?? '') === '1') {
            [$closed, $skipped] = IpBlock::addMany($nets, t('Боты: регистрации без подтверждения'), 0,
                (int)(Auth::realUser()['id'] ?? 0) ?: null);
            $msg .= ' ' . t('Закрыто подсетей: :n.', ['n' => $closed]);
            if ($skipped !== []) $msg .= ' ' . t('Не закрыты: :list.', ['list' => implode('; ', $skipped)]);
        }
        Flash::success($msg);
        redirect('/admin/users/bots');
    }
}
