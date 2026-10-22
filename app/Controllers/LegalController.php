<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErrorPage;
use App\Core\Legal;
use App\Core\View;

/**
 * Публичные страницы правовых документов (v3.9).
 *
 * Без входа: на политику ссылаются извне, её читают до регистрации, и требовать
 * ради этого аккаунт бессмысленно.
 */
final class LegalController
{
    /** GET /legal/{key} */
    public function show(string $key): never
    {
        /* Выключенный документ не «пустая страница», а отсутствующий: иначе
           поисковики и люди находили бы заглушки, которых владелец не заводил. */
        if (!Legal::enabled($key)) ErrorPage::render(404);

        View::render('legal', [
            'pageTitle' => Legal::title($key),
            'docKey' => $key,
            'body' => Legal::html($key),
            'version' => Legal::version($key),
            'updatedAt' => Legal::updatedAt($key),
        ]);
    }
}
