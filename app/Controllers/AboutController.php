<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;

/**
 * Страница «О сервисе» (v3.5).
 *
 * Вынесена из интерфейса намеренно: версия движка, его происхождение и история
 * релизов интересны один раз, а в шапке или подвале мозолят глаза каждый день.
 * Ссылка живёт в копирайте подвала — там её ищут и там она никому не мешает.
 */
final class AboutController
{
    public function index(): never
    {
        View::render('about', [
            'pageTitle' => t('О сервисе'),
            'releases' => \App\Core\Changelog::releases(),
        ]);
    }

}
