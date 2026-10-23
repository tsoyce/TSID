<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\BotSignal;
use App\Core\Flash;
use App\Core\IpBlock;
use App\Core\View;

/**
 * /admin/blocks — адреса (v4.95; блокировки — с v4.72). См. App\Core\IpBlock.
 *
 * Добавить правило можно и отсюда, и одной кнопкой со страницы адреса — туда
 * ведут адреса из журналов. Возврат — туда, откуда пришли. С v4.95 страница —
 * вкладка журналов, а не раздел настроек: адреса приходят из журналов, и
 * закрывают их, глядя на журналы.
 */
final class BlocksController extends AdminBase
{
    private const DAYS = [0, 1, 7, 30, 365];

    /** За сколько дней собирать подсети: ботов этого жанра видно за неделю, подбор — за месяц. */
    private const WINDOW = 30;

    public function index(): never
    {
        $this->requirePerm('settings.read');
        $this->requireGlobalAdmin();
        View::render('admin/blocks', [
            'pageTitle' => t('Адреса'),
            'nets' => BotSignal::subnets(self::WINDOW),
            'days' => self::WINDOW,
            'blocks' => IpBlock::all(),
            'myIp' => ip_str(ip_bin()),
        ]);
    }

    public function add(): never
    {
        $this->requirePerm('settings.write');
        $this->requireGlobalAdmin();
        $err = IpBlock::add((string)($_POST['rule'] ?? ''), (string)($_POST['reason'] ?? ''), self::days(),
            (int)(Auth::realUser()['id'] ?? 0) ?: null);
        $err === null ? Flash::success(t('Адрес заблокирован. С него не откроется ни одна страница.')) : Flash::error($err);
        redirect(back_to('/admin/blocks'));
    }

    /** Закрыть выбранные подсети пачкой. */
    public function bulk(): never
    {
        $this->requirePerm('settings.write');
        $this->requireGlobalAdmin();
        $nets = array_values(array_filter(array_map('strval', (array)($_POST['nets'] ?? []))));
        if ($nets === []) {
            Flash::info(t('Ничего не выбрано.'));
            redirect('/admin/blocks');
        }
        [$closed, $skipped] = IpBlock::addMany($nets, t('Боты и подбор: закрыто со страницы адресов'), self::days(),
            (int)(Auth::realUser()['id'] ?? 0) ?: null);
        Flash::success(t('Закрыто подсетей: :n.', ['n' => $closed])
            . ($skipped !== [] ? ' ' . t('Не закрыты: :list.', ['list' => implode('; ', $skipped)]) : ''));
        redirect('/admin/blocks');
    }

    public function remove(string $id): never
    {
        $this->requirePerm('settings.write');
        $this->requireGlobalAdmin();
        IpBlock::remove((int)$id);
        Flash::success(t('Блокировка снята.'));
        redirect(back_to('/admin/blocks'));
    }

    /** Снять выбранные блокировки пачкой. */
    public function removeMany(): never
    {
        $this->requirePerm('settings.write');
        $this->requireGlobalAdmin();
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        foreach ($ids as $id) IpBlock::remove($id);
        $ids === [] ? Flash::info(t('Ничего не выбрано.'))
            : Flash::success(t('Снято блокировок: :n.', ['n' => count($ids)]));
        redirect('/admin/blocks');
    }

    private static function days(): int
    {
        $days = (int)($_POST['days'] ?? 0);
        return in_array($days, self::DAYS, true) ? $days : 0;
    }
}
