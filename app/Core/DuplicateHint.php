<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Похоже, у человека уже есть аккаунт (v4.88).
 *
 * Владелец 27.09 вошёл через второй аккаунт Яндекса с другой почтой — и SSO
 * предложил завести новый аккаунт, хотя фамилия, имя и дата рождения совпали
 * с уже существующим. Почта у дубля другая, поэтому по ней (как в callback)
 * его не найти. Здесь — другие признаки:
 *   · номер телефона: его подтверждает сам сервис, и в базе он уникален;
 *   · фамилия, имя и полная дата рождения вместе.
 * Совпадение — только подсказка. Аккаунты не связываются сами: человек
 * входит в найденный аккаунт и этим доказывает, что он его (PendingLink), а
 * администратор объединяет аккаунты руками, видя обе стороны.
 */
final class DuplicateHint
{
    /**
     * Существующий аккаунт для профиля, пришедшего от сервиса.
     *
     * @return array{user: array, why: string}|null  why — 'phone' или 'name_birthdate'
     */
    public static function forProfile(array $profile): ?array
    {
        $phone = !empty($profile['phone']) ? User::normalizePhone((string)$profile['phone']) : '';
        if ($phone !== '') {
            $u = DB::row('SELECT * FROM {users} WHERE phone = ? LIMIT 1', [$phone]);
            if ($u && User::canSignIn($u)) return ['user' => $u, 'why' => 'phone'];
        }
        foreach (self::byNameAndBirthdate((string)($profile['first_name'] ?? ''), (string)($profile['last_name'] ?? ''),
            (string)($profile['birthdate'] ?? '')) as $u) {
            return ['user' => $u, 'why' => 'name_birthdate'];
        }
        return null;
    }

    /**
     * Возможные дубли аккаунта — для карточки в админке.
     *
     * Сильный признак — фамилия, имя и дата рождения; слабый — те же фамилия и
     * имя без даты (тёзки бывают, поэтому решает администратор).
     *
     * @return list<array{user: array, why: string}>  why — 'name_birthdate' или 'name'
     */
    public static function forUser(array $user, int $limit = 5): array
    {
        $first = self::norm((string)($user['first_name'] ?? ''));
        $last = self::norm((string)($user['last_name'] ?? ''));
        if ($first === '' || $last === '') return [];
        $out = [];
        foreach (DB::all('SELECT * FROM {users} WHERE id <> ? AND status <> ? ORDER BY id', [(int)$user['id'], 'deleted']) as $u) {
            if (self::norm((string)$u['first_name']) !== $first || self::norm((string)$u['last_name']) !== $last) continue;
            $sameBirth = !empty($user['birthdate']) && (string)$u['birthdate'] === (string)$user['birthdate'];
            $out[] = ['user' => $u, 'why' => $sameBirth ? 'name_birthdate' : 'name'];
        }
        usort($out, static fn(array $a, array $b): int => ($a['why'] === 'name') <=> ($b['why'] === 'name'));
        return array_slice($out, 0, $limit);
    }

    /** @return list<array> живые аккаунты с этими фамилией, именем и полной датой рождения */
    private static function byNameAndBirthdate(string $first, string $last, string $birth): array
    {
        $first = self::norm($first);
        $last = self::norm($last);
        if ($first === '' || $last === '' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $birth)) return [];
        $out = [];
        foreach (DB::all('SELECT * FROM {users} WHERE birthdate = ? ORDER BY id', [$birth]) as $u) {
            if (User::canSignIn($u) && self::norm((string)$u['first_name']) === $first && self::norm((string)$u['last_name']) === $last) {
                $out[] = $u;
            }
        }
        return $out;
    }

    /** «Артём», «АРТЕМ » и «артём» — одно имя: регистр, ё и лишние пробелы не в счёт. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim((string)preg_replace('~\s+~u', ' ', $s)));
        return strtr($s, ['ё' => 'е']);
    }

    /**
     * Как назвать найденный аккаунт человеку, не раскрывая его: почта с
     * первой буквой и доменом, а без почты — сервисы, через которые в него входят.
     */
    public static function describe(array $user): string
    {
        $email = (string)($user['email'] ?? '');
        if ($email !== '' && !str_ends_with($email, '.invalid') && str_contains($email, '@')) {
            [$local, $domain] = explode('@', $email, 2);
            return mb_substr($local, 0, 1) . '•••@' . $domain;
        }
        $titles = [];
        foreach (DB::all('SELECT DISTINCT provider FROM {linked_identities} WHERE user_id = ?', [(int)$user['id']]) as $row) {
            $p = \App\Social\Provider::get((string)$row['provider']);
            $titles[] = $p ? $p->title() : (string)$row['provider'];
        }
        return $titles === [] ? t('аккаунт без почты')
            : t('аккаунт, в который входили через :services', ['services' => implode(', ', $titles)]);
    }
}
