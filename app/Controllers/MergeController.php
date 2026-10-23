<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ErrorPage;
use App\Core\Flash;
use App\Core\RateLimiter;
use App\Core\View;
use App\Models\User;

/**
 * Слияние аккаунтов-дублей: экран «Это случайно не вы?» (v3.6).
 *
 * Сюда попадают из привязки соцсети: человек в основном аккаунте привязывает
 * Яндекс, а тот уже принадлежит другой учётке — почти всегда дублю, который
 * соцвход завёл раньше. Сессионный pending_merge кладёт SocialController,
 * и только он: прийти на этот экран с произвольным user_id нельзя.
 *
 * Доказательства владения:
 *  · соцаккаунтом — пройденный OAuth (без него pending_merge не появится);
 *  · дублем — его пароль, если он есть. Если пароля нет, соцвход был
 *    единственным способом входа в дубль, и OAuth уже доказал всё.
 */
final class MergeController
{
    private const TTL = 600; // 10 минут на решение — дальше начинать заново

    public function show(): never
    {
        $me = Auth::user();
        $pending = $this->pending();
        $dup = User::find($pending['dup_user_id']);
        if (!$dup || $dup['status'] === 'deleted') {
            unset($_SESSION['pending_merge']);
            Flash::error(t('Второй аккаунт недоступен — объединять нечего.'));
            redirect('/profile/security');
        }

        View::render('profile/merge', [
            'pageTitle' => t('Объединение аккаунтов'),
            'me' => $me,
            'dup' => $dup,
            'provider' => $pending['provider_title'],
            'needsPassword' => !empty($dup['password_hash']),
            'dupSummary' => $this->summary((int)$dup['id']),
        ]);
    }

    public function confirm(): never
    {
        $me = Auth::user();
        if (Auth::impersonating()) ErrorPage::render(403);
        $pending = $this->pending();
        RateLimiter::guard('merge:' . (int)$me['id'], 5, 900);

        $dup = User::find($pending['dup_user_id']);
        if (!$dup || $dup['status'] === 'deleted') {
            unset($_SESSION['pending_merge']);
            Flash::error(t('Второй аккаунт недоступен — объединять нечего.'));
            redirect('/profile/security');
        }

        /* Пароль дубля — если он есть. Дубль мог быть полноценным аккаунтом
           с паролем и своими данными; одного OAuth для него мало. */
        if (!empty($dup['password_hash'])) {
            $password = (string)($_POST['dup_password'] ?? '');
            if (!\App\Core\Auth::passwordOk($dup, $password)) {
                Flash::error(t('Пароль второго аккаунта не подошёл.'));
                redirect('/profile/merge');
            }
        }

        $stat = \App\Core\AccountMerge::into((int)$me['id'], (int)$dup['id']);
        unset($_SESSION['pending_merge']);

        Flash::success(t('Аккаунты объединены: способы входа, доступы и данные перенесены сюда.'));
        redirect('/profile/security#social');
    }

    public function cancel(): never
    {
        unset($_SESSION['pending_merge']);
        redirect('/profile/security');
    }

    /** @return array{dup_user_id: int, provider: string, provider_title: string, t: int} */
    private function pending(): array
    {
        $p = $_SESSION['pending_merge'] ?? null;
        if (!is_array($p) || (int)($p['t'] ?? 0) < time() - self::TTL) {
            unset($_SESSION['pending_merge']);
            Flash::info(t('Сессия объединения истекла. Начните привязку заново.'));
            redirect('/profile/security');
        }
        return $p;
    }

    /** Чем владеет дубль — чтобы человек видел, что именно переедет. */
    private function summary(int $dupId): array
    {
        return [
            'accesses' => (int)\App\Core\DB::val(
                'SELECT COUNT(*) FROM {user_client_access} WHERE user_id = ? AND status = ?', [$dupId, 'active']),
            'identities' => (int)\App\Core\DB::val(
                'SELECT COUNT(*) FROM {linked_identities} WHERE user_id = ?', [$dupId]),
            'passkeys' => \App\Models\Passkey::countForUser($dupId),
        ];
    }
}
