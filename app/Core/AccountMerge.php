<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\SessionModel;
use App\Models\User;
use App\Models\UserEmail;

/**
 * Слияние двух аккаунтов одного человека (v3.6).
 *
 * Откуда берутся дубли: человек вошёл через Яндекс раньше, чем привязал его к
 * основному аккаунту, — соцвход завёл новую учётку. Потом он входит в основной
 * аккаунт, пытается привязать Яндекс и упирается в «уже привязан к другому».
 * До v3.6 это был тупик; теперь — предложение объединить.
 *
 * Направление всегда одно: дубль вливается в тот аккаунт, где человек сидит
 * сейчас. Переносится всё, чем дубль владел; сам дубль остаётся в базе со
 * статусом deleted и ссылкой merged_into — аудит и история не осиротевают,
 * а email дубля освобождается и становится дополнительным адресом основного.
 */
final class AccountMerge
{
    /**
     * @return array{moved_identities: int, moved_access: int, moved_roles: int,
     *               moved_passkeys: int, moved_orgs: int}
     */
    public static function into(int $mainId, int $dupId, ?int $actorId = null): array
    {
        /* Кто объединил (v4.88): сам человек из профиля — или администратор. */
        $actorId ??= $mainId;
        if ($mainId === $dupId) {
            throw new \InvalidArgumentException('Нельзя слить аккаунт сам с собой');
        }
        $main = User::find($mainId);
        $dup = User::find($dupId);
        if (!$main || !$dup) throw new \RuntimeException('Аккаунт не найден');
        if ($dup['status'] === 'deleted') throw new \RuntimeException('Дубль уже удалён');

        $stat = [
            'moved_identities' => 0, 'moved_access' => 0, 'moved_roles' => 0,
            'moved_passkeys' => 0, 'moved_orgs' => 0,
        ];

        /* Перенос — одним куском (v4.45).
           До этого девять записей шли подряд без транзакции, и обрыв на любой
           из них оставлял состояние, которого не бывает: соцсвязки уже у
           основного, роли ещё у дубля, почта освобождена, а дубль всё ещё
           живой. Разобрать такое вручную нельзя — по строкам не видно, где
           остановились. Слияние либо случилось целиком, либо не начиналось.

           Сессии гасим здесь же, но СПИСОК их только собираем: разослать
           порталам можно лишь после commit — держать транзакцию открытой,
           пока чужой сервер думает, значит блокировать базу на его совести. */
        $sids = DB::tx(static function () use ($mainId, $dupId, $main, $dup, $actorId, &$stat): array {

        /* Соцсвязки — все: ради них слияние и затевается */
        $stat['moved_identities'] = DB::run(
            'UPDATE {linked_identities} SET user_id = ? WHERE user_id = ?', [$mainId, $dupId])->rowCount();

        /* Passkeys дубля продолжают работать в основном аккаунте */
        if (Migrations::ready('passkeys')) {
            $stat['moved_passkeys'] = DB::run(
                'UPDATE {passkeys} SET user_id = ? WHERE user_id = ?', [$mainId, $dupId])->rowCount();
        }

        /* Доступы к порталам: переносим только те, которых у основного нет —
           иначе упрёмся в PRIMARY KEY (user_id, client_id) */
        $stat['moved_access'] = DB::run(
            'UPDATE {user_client_access} SET user_id = ? WHERE user_id = ?
               AND client_id NOT IN (SELECT client_id FROM (SELECT client_id
                   FROM {user_client_access} WHERE user_id = ?) AS x)',
            [$mainId, $dupId, $mainId])->rowCount();
        DB::run('DELETE FROM {user_client_access} WHERE user_id = ?', [$dupId]);

        /* Роли — так же, без дублей */
        $stat['moved_roles'] = DB::run(
            'UPDATE {user_roles} SET user_id = ? WHERE user_id = ?
               AND role_id NOT IN (SELECT role_id FROM (SELECT role_id
                   FROM {user_roles} WHERE user_id = ?) AS x)',
            [$mainId, $dupId, $mainId])->rowCount();
        DB::run('DELETE FROM {user_roles} WHERE user_id = ?', [$dupId]);

        /* Членство в организациях */
        if (Migrations::ready('org_members')) {
            $stat['moved_orgs'] = DB::run(
                'UPDATE {org_members} SET user_id = ? WHERE user_id = ?
                   AND org_id NOT IN (SELECT org_id FROM (SELECT org_id
                       FROM {org_members} WHERE user_id = ?) AS x)',
                [$mainId, $dupId, $mainId])->rowCount();
            DB::run('DELETE FROM {org_members} WHERE user_id = ?', [$dupId]);
        }

        /* Данные профиля: дозаполняем пустое у основного, ничего не перетирая.
           Телефон под UNIQUE — сначала освобождаем его у дубля, иначе перенос
           падает на ограничении (поймано тестом, не глазами). */
        $fill = [];
        foreach (['phone', 'gender', 'birthdate', 'last_name', 'first_name'] as $f) {
            if (empty($main[$f]) && !empty($dup[$f])) $fill[$f] = $dup[$f];
        }
        if (isset($fill['phone'])) {
            DB::run('UPDATE {users} SET phone = NULL WHERE id = ?', [$dupId]);
            $fill['phone_verified_at'] = $dup['phone_verified_at'];
        }
        if ($fill !== []) User::update($mainId, $fill);

        /* Email дубля становится дополнительным адресом основного. Сначала
           освобождаем его в users (UNIQUE), затем регистрируем как доп. */
        $dupEmail = (string)$dup['email'];
        $dupVerified = $dup['email_verified_at'] !== null;
        UserEmail::moveAll($dupId, $mainId);
        User::update($dupId, [
            'email' => 'merged-' . $dupId . '@merged.invalid',
            'status' => 'deleted',
            'merged_into' => $mainId,
        ]);
        if (!str_ends_with($dupEmail, '.invalid')) {
            UserEmail::add($mainId, $dupEmail, 'merge', $dupVerified);
        }

        /* Сессии и токены дубля гасим везде; рассылка — после фиксации */
        $sids = SessionModel::revokeAllForUser($dupId);
        \App\Models\RefreshToken::revokeAllForUser($dupId);

        Audit::log('account.merged', ['actor' => $actorId, 'on' => $dupId,
            'meta' => ['into' => $mainId, 'from_email' => $dupEmail] + ($actorId !== $mainId ? ['by' => 'admin'] : []) + $stat]);
        return $sids;
        });

        /* Дальше — только оповещения. База уже в согласованном состоянии, и
           неответивший портал больше ничего не испортит. */
        foreach ($sids as $sid) {
            \App\Oidc\EventDispatcher::sessionLogout($sid);
        }
        /* Порталам — тоже: у них остаётся живой аккаунт под чужим sub, который
           больше никогда не войдёт. Уходил только вебхук, а мост слушает свой
           приёмник событий, а не подписки. */
        \App\Oidc\EventDispatcher::userDeleted($dup, 'merged',
            ['reason' => 'merged', 'merged_into' => $main['public_id']]);
        \App\Core\WebhookDispatcher::emit('user.deleted', [
            'sub' => $dup['public_id'], 'reason' => 'merged',
            'merged_into' => $main['public_id'],
        ]);
        return $stat;
    }
}
