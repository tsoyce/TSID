<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\IpBlock;

/**
 * /admin/blocks — блокировка адресов (v4.72). См. App\Core\IpBlock.
 *
 * Добавить правило можно и отсюда, и одной кнопкой со страницы адреса — туда
 * ведут адреса из журналов. Возврат — туда, откуда пришли.
 */
final class BlocksController extends AdminBase
{
    public function index(): never
    {
        $this->requirePerm('settings.read');
        $this->requireGlobalAdmin();
        settings_page('blocks', 'admin/blocks', [
            'pageTitle' => t('Блокировки'),
            'blocks' => IpBlock::all(),
            'myIp' => ip_str(ip_bin()),
        ]);
    }

    public function add(): never
    {
        $this->requirePerm('settings.write');
        $this->requireGlobalAdmin();
        $days = (int)($_POST['days'] ?? 0);
        if (!in_array($days, [0, 1, 7, 30, 365], true)) $days = 0;
        $err = IpBlock::add((string)($_POST['rule'] ?? ''), (string)($_POST['reason'] ?? ''), $days,
            (int)(Auth::realUser()['id'] ?? 0) ?: null);
        $err === null ? Flash::success(t('Адрес заблокирован. С него не откроется ни одна страница.')) : Flash::error($err);
        redirect(back_to('/admin/blocks'));
    }

    public function remove(string $id): never
    {
        $this->requirePerm('settings.write');
        $this->requireGlobalAdmin();
        IpBlock::remove((int)$id);
        Flash::success(t('Блокировка снята.'));
        redirect(back_to('/admin/blocks'));
    }
}
