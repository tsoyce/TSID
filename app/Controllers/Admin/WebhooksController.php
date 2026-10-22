<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\ErrorPage;
use App\Core\Flash;
use App\Core\View;
use App\Core\WebhookDispatcher;
use App\Models\Webhook;

/** /admin/webhooks: универсальный канал событий для сторонних систем. */
final class WebhooksController extends AdminBase
{
    public function index(): never
    {
        $this->requireGlobalAdmin();
        $hooks = Webhook::all();
        $deliveries = [];
        foreach ($hooks as $h) {
            $deliveries[(int)$h['id']] = Webhook::recentDeliveries((int)$h['id'], 5);
        }
        settings_page('webhooks', 'admin/webhooks', [
            'pageTitle' => 'Webhooks',
            'hooks' => $hooks,
            'deliveries' => $deliveries,
            'catalog' => Webhook::events(),
            'freshSecret' => (function () {
                $s = $_SESSION['fresh_webhook_secret'] ?? null;
                unset($_SESSION['fresh_webhook_secret']);
                return $s;
            })(),
        ], '<button class="btn btn-primary" type="button" data-modal-open="hook-modal">'
           . icon('plus', 16) . ' ' . esc(t('Добавить webhook')) . '</button>');
    }

    public function create(): never
    {
        $this->requireGlobalAdmin();
        $url = trim((string)($_POST['url'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || !preg_match('~^https?://[^\s]+$~', $url)) {
            Flash::error('Нужны название и корректный URL приёмника.');
            redirect('/admin/webhooks');
        }
        [$id, $secret] = Webhook::create([
            'name' => $name,
            'url' => $url,
            'events' => (array)($_POST['events'] ?? []),
        ], (int)Auth::id());
        $_SESSION['fresh_webhook_secret'] = ['id' => $id, 'secret' => $secret];
        Audit::log('admin.webhook.create', ['meta' => ['name' => $name]]);
        Flash::success('Webhook создан. Секрет показан один раз — сохраните его.');
        redirect('/admin/webhooks');
    }

    public function action(string $id): never
    {
        $this->requireGlobalAdmin();
        $hook = Webhook::find((int)$id);
        if (!$hook) ErrorPage::render(404);

        switch ((string)($_POST['action'] ?? '')) {
            case 'toggle':
                Webhook::update((int)$hook['id'], ['is_active' => (int)$hook['is_active'] ? 0 : 1]);
                Flash::success((int)$hook['is_active'] ? t('Webhook выключен.') : t('Webhook включён.'));
                break;

            case 'update_events':
                Webhook::update((int)$hook['id'], ['events' => (array)($_POST['events'] ?? [])]);
                Audit::log('admin.webhook.update', ['meta' => ['id' => (int)$hook['id']]]);
                Flash::success('Подписка на события обновлена.');
                break;

            case 'test':
                $res = WebhookDispatcher::test($hook);
                $res['ok']
                    ? Flash::success(t('Пробное событие доставлено (HTTP :code).', ['code' => $res['code']]))
                    : Flash::error(t('Не доставлено: :why', ['why' => $res['error'] ?: t('нет ответа')]));
                break;

            case 'delete':
                Webhook::delete((int)$hook['id']);
                Audit::log('admin.webhook.delete', ['meta' => ['name' => $hook['name']]]);
                Flash::success('Webhook удалён.');
                redirect('/admin/webhooks');
        }
        redirect('/admin/webhooks');
    }
}
