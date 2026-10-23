<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\RefreshToken;
use App\Models\SessionModel;
use App\Models\User;

/**
 * Блокировка человека — одна на админку, Admin API и SCIM (v4.98).
 *
 * Было три копии, и разошлись они ровно там, где это опасно: API не отзывал
 * refresh-токены (портал продолжал обновлять доступ заблокированному), а SCIM
 * не оповещал порталы вовсе и блокировал даже суперадмина.
 */
final class UserBlock
{
    /** Суперадмина не блокируют: это последний, кто может всё вернуть. */
    public static function forbidden(int $userId): bool
    {
        return in_array('superadmin', User::globalRoleSlugs($userId), true);
    }

    /** Заблокировать: статус, сессии с оповещением порталов, refresh-токены, события. */
    public static function apply(array $user, string $via): void
    {
        $uid = (int)$user['id'];
        User::update($uid, ['status' => 'blocked']);
        foreach (SessionModel::activeForUser($uid) as $s) {
            \App\Oidc\EventDispatcher::sessionLogout((int)$s['id']);
        }
        SessionModel::revokeAllForUser($uid);
        RefreshToken::revokeAllForUser($uid);
        \App\Oidc\EventDispatcher::userEvent($user, 'user.blocked');
        WebhookDispatcher::emit('user.blocked', ['sub' => $user['public_id'], 'email' => $user['email'], 'via' => $via]);
    }
}
