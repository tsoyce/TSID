<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\OneTimeToken;
use App\Models\Role;

/**
 * Политика новых аккаунтов (v4.41).
 *
 * Две настройки, которых не было, и одно место вместо пяти.
 *
 * Роль новичка была вписана в код пятью `Role::findBySlug('user')` — в
 * регистрации, в соцвходе, в импорте, в переносе с портала и в Admin API.
 * Поменять её было нельзя нигде, а «поменять во всех пяти» рано или поздно
 * означает «поменять в четырёх».
 *
 * Открытость регистрации не настраивалась вовсе: зарегистрироваться мог любой,
 * у кого есть почта, и единственным ограничителем был список доменов. Для
 * закрытого контура этого мало — нужна дверь, которую можно закрыть, и ключ,
 * который можно выдать конкретному человеку.
 *
 * По умолчанию всё как было: регистрация открыта, роль — «Пользователь».
 * Принцип опциональности: настройка появляется, поведение не меняется.
 */
final class Signup
{
    public const INVITE = 'signup_invite';

    /** Можно ли зарегистрироваться самому. */
    public static function open(): bool
    {
        return Setting::get('policy.signup_open', '1') !== '0';
    }

    /** Слаг роли, которую получает новый аккаунт. */
    public static function defaultRoleSlug(): string
    {
        $slug = trim((string)Setting::get('policy.default_role', 'user'));
        return $slug !== '' ? $slug : 'user';
    }

    /**
     * Роль новичка одним числом.
     *
     * Возвращает null, если роль не нашлась: аккаунт всё равно заведётся, но
     * без роли — это лучше, чем не завестись вовсе из-за опечатки в настройке.
     * Такое молчать нельзя, поэтому пишем в журнал.
     */
    public static function defaultRoleId(): ?int
    {
        $slug = self::defaultRoleSlug();
        $role = Role::findBySlug($slug);
        if ($role) return (int)$role['id'];

        Log::warning('signup.default_role_missing', ['slug' => $slug]);
        /* Падать назад на «user» — единственный разумный запасной путь: это
           роль, которую система заводит при установке и не удаляет. */
        $fallback = Role::findBySlug('user');
        return $fallback ? (int)$fallback['id'] : null;
    }

    /** Выдать роль новичку. Одно место на все пять точек входа. */
    public static function grantDefaultRole(int $userId): void
    {
        $rid = self::defaultRoleId();
        if ($rid !== null) Role::assign($userId, $rid);
    }

    /**
     * Роли, которые разумно предложить как роль новичка.
     *
     * Только системные (не привязанные к порталу) и не старше «пользователя» (уровень 20):
     * выдать новичку администратора одним выпадающим списком нельзя. Это не
     * недоверие к владельцу — это защита от промаха мышью в настройках,
     * который иначе раздаст права всем, кто зарегистрируется следующим.
     *
     * @return array<string,string> слаг => подпись
     */
    public static function roleChoices(): array
    {
        $out = [];
        foreach (Role::all() as $r) {
            if (($r['client_id'] ?? null) !== null) continue;
            if ((int)($r['access_level'] ?? 0) > 20) continue;
            $out[(string)$r['slug']] = $r['name'] . ' (' . $r['slug'] . ')';
        }
        if ($out === []) $out['user'] = t('Пользователь (user)');
        return $out;
    }

    /* ---------- Приглашения в систему ---------- */

    /**
     * Ссылка-приглашение на регистрацию.
     *
     * Нужна ровно тогда, когда регистрация закрыта: дверь заперта, а этому
     * человеку войти надо. Не путать с приглашением в ПОРТАЛ — то про доступ к
     * сервису и живёт в его карточке; это про появление аккаунта вообще.
     *
     * @return array{token:string, url:string}
     */
    public static function issueInvite(?string $email, ?int $by): array
    {
        $email = $email !== null ? mb_strtolower(trim($email)) : null;
        if ($email === '') $email = null;
        $token = OneTimeToken::issue(null, self::INVITE,
            array_filter(['email' => $email, 'by' => $by], static fn($v) => $v !== null));
        return ['token' => $token, 'url' => base_url('/register?invite=' . urlencode($token))];
    }

    /** Приглашение из запроса, если оно годное. */
    public static function inviteFromRequest(): ?array
    {
        $token = trim((string)($_POST['invite'] ?? $_GET['invite'] ?? ''));
        if ($token === '') return null;
        try {
            $row = OneTimeToken::peek($token, self::INVITE);
        } catch (\Throwable) {
            return null;
        }
        return $row === null ? null : ['token' => $token, 'payload' => $row['payload'] ?? []];
    }

    /**
     * Пускать ли этого человека регистрироваться.
     *
     * Открытая регистрация пускает всех. Закрытая — только с приглашением, и
     * если в приглашении названа почта, то именно с ней: иначе одна ссылка,
     * пересланная дальше, открывает дверь кому угодно.
     */
    public static function allowed(?string $email): bool
    {
        if (self::open()) return true;
        $inv = self::inviteFromRequest();
        if ($inv === null) return false;
        $bound = (string)($inv['payload']['email'] ?? '');
        if ($bound === '') return true;
        return $email !== null && mb_strtolower(trim($email)) === $bound;
    }

    /** Погасить приглашение после успешной регистрации. */
    public static function consumeInvite(): void
    {
        $inv = self::inviteFromRequest();
        if ($inv === null) return;
        try {
            OneTimeToken::consume($inv['token'], self::INVITE);
        } catch (\Throwable $e) {
            Log::warning('signup.invite_consume_failed', ['err' => $e->getMessage()]);
        }
    }
}
