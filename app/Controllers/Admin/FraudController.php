<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\BotSignal;
use App\Core\FraudArchive;
use App\Core\IpBlock;
use App\Core\Stats;
use App\Core\View;

/**
 * /admin/fraud — «Антифрод» (v5.5).
 *
 * Боты и подбор разбирались на трёх экранах — «Похоже на ботов», «Адреса»,
 * журнал аудита, — и ни один не отвечал на вопрос «что происходит в целом»:
 * сколько отказано, откуда идут, сработал ли фильтр, чем притворяются. Здесь
 * — сводка за месяц, архив удалённых ботов и его выгрузка. Действия остаются
 * там, где были: удалить — на «Похоже на ботов», закрыть — на «Адресах».
 */
final class FraudController extends AdminBase
{
    /** Окно сводки, дней: ботов этого жанра видно за неделю, подбор — за месяц. */
    private const DAYS = 30;

    public function index(): never
    {
        $this->requirePerm('settings.read');
        $this->requireGlobalAdmin();
        View::render('admin/fraud', [
            'pageTitle' => t('Антифрод'),
            'days' => self::DAYS,
            'suspects' => BotSignal::suspectCount(),
            'refused' => self::perDay('register.refused'),
            'fails' => self::perDay('login.fail'),
            'archived' => FraudArchive::perDay(self::DAYS),
            'archiveTotal' => FraudArchive::count(),
            'blocks' => count(IpBlock::all()),
            'nets' => array_slice(BotSignal::subnets(self::DAYS), 0, 6),
            'summary' => FraudArchive::summary(),
            'samples' => FraudArchive::recent(30),
            'keepDays' => \App\Core\Setting::getInt('retention.fraud_days', FraudArchive::KEEP_DAYS),
        ]);
    }

    /**
     * GET /admin/fraud/samples.json — архив образцов файлом: разобрать у себя,
     * обучить фильтр, сравнить с другой установкой. Образцы обезличены при
     * записи, поэтому выгрузка ничего не вырезает; сам факт выгрузки — в аудит.
     */
    public function export(): never
    {
        $this->requirePerm('settings.read');
        $this->requireGlobalAdmin();
        $rows = FraudArchive::export();
        Audit::log('admin.fraud.export', ['meta' => ['samples' => count($rows)]]);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="fraud-samples-' . gmdate('Ymd-Hi') . '.json"');
        header('Cache-Control: no-store');
        echo json_encode(['version' => SSO_VERSION, 'exported_at' => now(), 'weights' => BotSignal::WEIGHT,
            'samples' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /** События аудита по местным суткам за окно — тем же счётом, что пульт (Stats). */
    private static function perDay(string $event): array
    {
        return array_values(Stats::perDay('audit_log', 'event = ?', [$event], self::DAYS));
    }
}
