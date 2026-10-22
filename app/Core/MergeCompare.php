<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Две стороны объединения в админке (v4.88): найти второй аккаунт и показать
 * обе рядом — чем каждая владеет. Слияние делает AccountMerge; здесь только
 * то, по чему администратор решает, какой аккаунт оставить.
 */
final class MergeCompare
{
    /** Аккаунт по номеру (#12), почте (и дополнительной), логину, телефону или sub. */
    public static function find(string $q): ?array
    {
        $q = trim($q);
        if ($q === '') return null;
        if (preg_match('~^#?(\d{1,9})$~', $q, $m)) {
            $u = User::find((int)$m[1]);
            if ($u) return $u;
        }
        if (str_contains($q, '@')) {
            $u = \App\Models\UserEmail::findOwner(mb_strtolower($q)) ?? User::findByEmail(mb_strtolower($q));
            if ($u) return User::find((int)$u['id']);
        }
        $u = User::findByLoginOrEmail($q) ?? User::findByPublicId($q);
        if ($u) return $u;
        $phone = User::normalizePhone($q);
        if ($phone !== '') {
            $u = DB::row('SELECT * FROM {users} WHERE phone = ? LIMIT 1', [$phone]);
            if ($u) return $u;
        }
        return null;
    }

    /**
     * Что у аккаунта есть — строками «подпись → значение».
     *
     * @return array{user: array, rows: array<string, string>}
     */
    public static function side(array $u): array
    {
        $id = (int)$u['id'];
        $count = static fn(string $table): int => Migrations::ready($table)
            ? (int)DB::val('SELECT COUNT(*) FROM {' . $table . '} WHERE user_id = ?', [$id]) : 0;
        $services = [];
        foreach (DB::all('SELECT DISTINCT provider FROM {linked_identities} WHERE user_id = ?', [$id]) as $row) {
            $p = \App\Social\Provider::get((string)$row['provider']);
            $services[] = $p ? $p->title() : (string)$row['provider'];
        }
        $extra = array_map(static fn(array $e): string => (string)$e['email'], \App\Models\UserEmail::forUser($id));
        $email = str_ends_with((string)$u['email'], '.invalid') ? '' : (string)$u['email'];
        return ['user' => $u, 'rows' => [
            t('Имя') => user_name($u),
            t('Почта') => $email !== '' ? $email : '—',
            t('Дополнительные адреса') => $extra === [] ? '—' : implode(', ', $extra),
            t('Логин') => (string)($u['login'] ?? '') !== '' ? (string)$u['login'] : '—',
            t('Телефон') => (string)($u['phone'] ?? '') !== '' ? (string)$u['phone'] : '—',
            t('Дата рождения') => (string)($u['birthdate'] ?? '') !== '' ? fmt_date((string)$u['birthdate']) : '—',
            t('Вход через сервисы') => $services === [] ? '—' : implode(', ', $services),
            t('Ключи устройства') => (string)$count('passkeys'),
            t('Роли') => (string)$count('user_roles'),
            t('Доступ к сервисам') => (string)$count('user_client_access'),
            t('Организации') => (string)$count('org_members'),
            t('Статус') => match ((string)$u['status']) {
                'active' => t('Активен'), 'pending' => t('Ожидает почту'), 'blocked' => t('Заблокирован'),
                'deleted' => t('Удалён'), default => (string)$u['status'],
            },
            t('Создан') => fmt_dt((string)$u['created_at']),
            t('Последний вход') => (string)($u['last_login_at'] ?? '') !== '' ? fmt_dt((string)$u['last_login_at']) : '—',
        ]];
    }
}
