<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use App\Models\UserEmail;
use App\Models\UserPhoto;

/**
 * Что взять из профиля, который отдал соцпровайдер (v3.8).
 *
 * Правило одно и оно важнее кода: **дозаполняем только пустое**. Провайдер —
 * источник удобства, а не истины. Человек, который поправил у себя фамилию,
 * не должен обнаружить её обратно затёртой после входа через Яндекс.
 *
 * Раньше это жило приватным методом в SocialController и проверялось только
 * живым соцвходом, то есть на практике не проверялось никак. Здесь оно
 * вызывается напрямую и тестом тоже.
 */
final class ProfileAbsorb
{
    /**
     * @param  array $profile нормализованный ответ провайдера (см. App\Social\*)
     * @return list<string>   какие поля были заполнены — для аудита и тестов
     */
    public static function apply(int $userId, string $provider, array $profile): array
    {
        $user = User::find($userId);
        if (!$user) return [];

        $fill = [];

        if (empty($user['phone']) && !empty($profile['phone'])) {
            $phone = \App\Models\User::normalizePhone((string)$profile['phone']);
            /* UNIQUE на users.phone: чужой номер молча не забираем */
            if ($phone !== '' && !\App\Models\User::phoneTaken($phone)) {
                $fill['phone'] = $phone;
                $fill['phone_verified_at'] = now();   // номер подтверждён провайдером
            }
        }

        if (empty($user['gender']) && in_array($profile['gender'] ?? null, ['male', 'female'], true)
            && DB::columnExists('users', 'gender')) {
            $fill['gender'] = $profile['gender'];
        }

        /* Имя и фамилия по отдельности — там, где провайдер их знает. До v3.8
           бралась только склеенная строка, и «Артём Петров» целиком уезжал в
           first_name: фамилия оставалась пустой, а MRZ выходил кривым. */
        foreach (['first_name', 'last_name'] as $field) {
            if (empty($user[$field]) && !empty($profile[$field])) {
                $fill[$field] = mb_substr(trim((string)$profile[$field]), 0, 100);
            }
        }

        /* Провайдер знает только слитное имя (так у GitHub) — кладём его в
           display_name, для которого оно и предназначено, а не режем наугад по
           пробелу: «Анна Мария Ковалевская» разрежется неправильно. */
        if (empty($user['display_name']) && empty($user['first_name']) && empty($user['last_name'])
            && empty($profile['first_name']) && empty($profile['last_name']) && !empty($profile['name'])) {
            $fill['display_name'] = mb_substr(trim((string)$profile['name']), 0, 150);
        }

        if (empty($user['birthdate']) && !empty($profile['birthdate'])
            && DB::columnExists('users', 'birthdate')) {
            $fill['birthdate'] = $profile['birthdate'];
        }

        if ($fill !== []) {
            User::update($userId, $fill);
            /* След в аудите: и человек, и админ должны видеть, что именно
               приехало от провайдера, а не гадать, откуда взялись данные. */
            Audit::log('profile.absorbed', ['actor' => $userId, 'on' => $userId,
                'meta' => ['provider' => $provider, 'fields' => array_keys($fill)]]);
        }

        /* Адрес провайдера — в дополнительные: по нему аккаунт найдётся при
           следующем входе через другого провайдера с той же почтой. */
        $email = isset($profile['email']) ? mb_strtolower(trim((string)$profile['email'])) : '';
        if ($email !== '' && $email !== mb_strtolower((string)$user['email'])) {
            UserEmail::add($userId, $email, $provider, true);
        }

        /* Фотография — в галерею, а не в аватарку: выбирает человек. Ссылку
           провайдер присылает при каждом входе, но качается она только когда
           новая, поэтому обычный вход ничего не ждёт. Свежие данные читаем
           заново — выше мы могли поменять сам аккаунт. */
        if (!empty($profile['avatar'])) {
            UserPhoto::fetchFromProvider(User::find($userId) ?? $user, $provider, (string)$profile['avatar']);
        }

        return array_keys($fill);
    }
}
