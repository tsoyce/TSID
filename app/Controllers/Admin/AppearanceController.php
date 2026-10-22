<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Appearance;
use App\Core\Audit;
use App\Core\Flash;
use App\Core\Setting;
use App\Core\View;
use App\Models\Client;

/**
 * /admin/appearance — оформление сайта: главная и экран входа (v4.8).
 *
 * Отдельным разделом, а не восьмой вкладкой настроек. Причина не в размере, а
 * в том, кто это видит: настройки — про то, как установка работает, а здесь
 * про то, как её видит НЕ вошедший человек. Это две страницы из тридцати,
 * единственные, которые показывают посторонним, и единственные, на которые
 * смотрят с чужого сайта.
 *
 * Снимок панели переехал сюда из вкладки «Брендинг». Там он лежал рядом с
 * логотипом и favicon и выглядел их продолжением, хотя логотип видно везде, а
 * панель — только на входе.
 */
final class AppearanceController extends AdminBase
{
    public function index(): never
    {
        $this->requirePerm('settings.read');
        $this->requireGlobalAdmin();

        /* Предпросмотр экрана входа — во фрейме со своего адреса (v4.71). */
        \App\Core\Csp::allow('frame-src', "'self'");
        settings_page('appearance', 'admin/appearance', [
            'pageTitle' => t('Оформление сайта'),
            'portals' => Client::all(),
            'social' => \App\Social\Provider::enabled(),
            'siteTheme' => \App\Core\Bridge::sanitize(json_decode((string)Setting::get('look.theme', ''), true), 'site'),
        ], '<a class="btn btn-secondary" href="/admin/settings">' . esc(t('К брендингу →')) . '</a>');
    }

    /** POST /admin/appearance/fonts — установить шрифт из Google Fonts или убрать установленный (v4.78). */
    public function fonts(): never
    {
        $this->requirePerm('settings.write');
        $this->requireGlobalAdmin();
        $remove = trim((string)($_POST['remove'] ?? ''));
        $res = $remove !== ''
            ? \App\Core\FontLibrary::remove($remove)
            : \App\Core\FontLibrary::install((string)($_POST['family'] ?? ''));
        $res['ok'] ? Flash::success($res['detail']) : Flash::error($res['detail']);
        if ($res['ok']) Audit::log($remove !== '' ? 'settings.font.removed' : 'settings.font.installed',
            ['meta' => ['font' => $remove !== '' ? $remove : (string)($_POST['family'] ?? '')]]);
        redirect('/admin/appearance#fonts');
    }

    /** Страница для фрейма предпросмотра: экран входа установки с черновиком. */
    public function preview(): never
    {
        $this->requirePerm('settings.read');
        $this->requireGlobalAdmin();
        \App\Core\LoginLook::renderPreview(null, $_GET);
    }

    /**
     * Палитра обеих тем из одного цвета — для кнопки «Собрать из цвета».
     *
     * Общая для редактора портала и редактора установки: чистая функция от
     * цвета, ничего не читает и не пишет.
     */
    /**
     * POST /admin/look/schemes — сохранить оформление из редактора своей схемой (v4.79).
     * Отвечает плиткой: редактор вставляет её, не теряя несохранённой формы.
     */
    public function schemeSave(): never
    {
        $this->requirePerm('settings.write');
        $this->requireGlobalAdmin();
        $theme = \App\Core\LoginLook::fromForm((array)($_POST['look'] ?? []), 'site');
        $res = \App\Core\LookSchemes::save((string)($_POST['name'] ?? ''), $theme);
        if (!$res['ok']) json_response(['ok' => false, 'message' => $res['detail']]);
        Audit::log('settings.look.scheme_saved', ['meta' => ['name' => $res['scheme']['name']]]);
        json_response(['ok' => true, 'message' => $res['detail'], 'id' => $res['scheme']['id'],
            'html' => View::fetch('admin/_look_scheme_tile', ['scheme' => $res['scheme'], 'canDrop' => true])]);
    }

    /** POST /admin/look/schemes/drop — убрать свою схему. */
    public function schemeDrop(): never
    {
        $this->requirePerm('settings.write');
        $this->requireGlobalAdmin();
        $ok = \App\Core\LookSchemes::remove((string)($_POST['id'] ?? ''));
        if ($ok) Audit::log('settings.look.scheme_removed', ['meta' => ['id' => (string)($_POST['id'] ?? '')]]);
        json_response(['ok' => $ok, 'message' => $ok ? t('Схема убрана.') : t('Такой схемы нет.')]);
    }

    public function palette(): never
    {
        $accent = (string)($_GET['accent'] ?? '');
        if (!preg_match('~^#?[0-9A-Fa-f]{6}$~', $accent)) json_response(['error' => 'accent'], 400);
        json_response(\App\Core\Bridge::paletteFrom('#' . ltrim($accent, '#')));
    }

    public function save(): never
    {
        $this->requirePerm('settings.write');
        $this->requireGlobalAdmin();

        /* Экран сохраняется одной кнопкой, а не двумя: полос сохранения на
           странице должно быть ровно одна. Разбиение по частям осталось для
           того, кто шлёт форму по частям, — тогда трогается только названная
           часть, и «нет ни одного способа входа» не срабатывает на запросе,
           который про вход вообще не был. */
        $section = (string)($_POST['section'] ?? 'all');
        if (!in_array($section, ['all', 'home', 'login'], true)) {
            Flash::error(t('Неизвестный раздел оформления.'));
            redirect('/admin/appearance');
        }

        if ($section === 'all' || $section === 'home') {
            Setting::set('appearance.showcase', !empty($_POST['showcase']) ? '1' : '0');
            Setting::set('appearance.cover_shots', !empty($_POST['cover_shots']) ? '1' : '0');
            /* Состав витрины правится здесь же, а не по одному в карточках
               порталов: решение «что показать постороннему» принимают
               разом, глядя на весь список, а не обходя тридцать карточек. */
            $picked = array_map('intval', (array)($_POST['portal'] ?? []));
            foreach (Client::all() as $c) {
                $want = in_array((int)$c['id'], $picked, true) ? 1 : 0;
                if ((int)($c['show_on_home'] ?? 0) !== $want) {
                    Client::update((int)$c['id'], ['show_on_home' => $want]);
                }
            }
        }

        if ($section === 'all' || $section === 'login') {
            /* Запрет на «выключить всё»: без единого способа войти нельзя
               никому, включая того, кто это выключил. Проверяем ДО записи —
               иначе выход из положения только через базу. */
            $left = 0;
            foreach (Appearance::METHODS as $m) {
                if (empty($_POST['method_' . $m])) continue;
                if ($m === 'social' && \App\Social\Provider::enabled() === []) continue;
                $left++;
            }
            if ($left === 0) {
                Flash::error(t('Хотя бы один способ входа обязан остаться: иначе войти не сможет никто, включая вас.'));
                redirect('/admin/appearance');
            }
            foreach (Appearance::METHODS as $m) {
                Setting::set('appearance.method_' . $m, !empty($_POST['method_' . $m]) ? '1' : '0');
            }
            Setting::set('appearance.look_everywhere', !empty($_POST['look_everywhere']) ? '1' : '0');
            Setting::set('appearance.social_first', !empty($_POST['social_first']) ? '1' : '0');
            /* Чей дизайн главнее на входе в порталы (v4.85). */
            if (in_array($_POST['portal_look'] ?? '', Appearance::PORTAL_LOOKS, true)) {
                Setting::set('appearance.portal_look', (string)$_POST['portal_look']);
            }
            Setting::set('branding.media_title', mb_substr(trim((string)($_POST['media_title'] ?? '')), 0, 80));
            Setting::set('branding.media_text', mb_substr(trim((string)($_POST['media_text'] ?? '')), 0, 160));
            /* Обложка панели — набором, тем же, что у порталов (v4.81). */
            foreach (\App\Core\CoverSet::saveFromForm(null, $_POST, $_FILES) as $why) Flash::error($why);
            /* Фон страниц (v4.84) — только если форма его прислала: частичное
               сохранение не должно стирать фон, которого не касалось. */
            if (isset($_POST['backdrop_veil'])) {
                foreach (\App\Core\Backdrop::saveFromForm($_POST, $_FILES) as $why) Flash::error($why);
            }
            /* Оформление экрана входа установки (v4.71) — тем же редактором, что
               у порталов. Что из палитры не применится, говорим сразу. */
            if (isset($_POST['look'])) {
                foreach (\App\Core\LoginLook::saveForSite($_POST) as $why) Flash::info($why);
            }
        }

        Setting::flush();
        Audit::log('admin.appearance.update', ['meta' => ['section' => $section]]);
        Flash::success(t('Оформление сохранено.'));
        redirect('/admin/appearance');
    }
}
