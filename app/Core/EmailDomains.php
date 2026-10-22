<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Кому можно заводить аккаунт: ограничение по домену почты.
 *
 * Установка на весь интернет и установка «только сотрудники» — это одна и та же
 * программа с разным ответом на один вопрос. До этого ответа не было вовсе:
 * зарегистрироваться мог любой, кто дошёл до формы, и единственным способом
 * закрыть регистрацию было выключить её целиком.
 *
 * Пусто — значит любой домен: по умолчанию ничего не ограничено (§1.1).
 *
 * Список именно разрешающий, а не запрещающий. Запрещающий список требует
 * перечислить весь мир, и потому не работает: завтра появится новый бесплатный
 * почтовик, и он окажется разрешён по умолчанию. Разрешающий ошибается в
 * безопасную сторону — кого забыли, тот не войдёт и скажет об этом.
 *
 * Ограничение действует ТОЛЬКО на самостоятельную регистрацию. Приглашение,
 * импорт, SCIM и Admin API его не спрашивают: там аккаунт заводит хозяин
 * установки, и он уже решил.
 */
final class EmailDomains
{
    /** Разрешённые домены, приведённые к нижнему регистру. Пусто = любой. */
    public static function allowed(): array
    {
        $raw = trim((string)Setting::get('policy.email_domains', ''));
        if ($raw === '') return [];
        $out = [];
        foreach (preg_split('~[\s,;]+~u', mb_strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $d) {
            /* Пишут по-разному: «@example.com», «example.com», «*.example.com».
               Принимаем всё это и храним одинаково — иначе список работает
               через раз, а почему, не видно. */
            $d = ltrim($d, '@*.');
            if ($d !== '') $out[$d] = true;
        }
        return array_keys($out);
    }

    public static function restricted(): bool
    {
        return self::allowed() !== [];
    }

    /**
     * Пускаем ли этот адрес.
     *
     * Поддомены считаются своими: `mail.example.com` проходит по правилу
     * `example.com`. Организации живут на поддоменах, и требовать перечислить
     * каждый — способ получить список, который никто не поддерживает.
     */
    public static function allows(string $email): bool
    {
        $list = self::allowed();
        if ($list === []) return true;
        $at = strrpos($email, '@');
        if ($at === false) return false;
        $host = mb_strtolower(substr($email, $at + 1));
        foreach ($list as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) return true;
        }
        return false;
    }

    /** Что сказать человеку, которого не пустили. */
    public static function refusal(): string
    {
        $list = self::allowed();
        return t('Регистрация открыта только для адресов: :list. Если ваш адрес должен подойти — попросите администратора.', [
            'list' => implode(', ', array_map(static fn(string $d): string => '@' . $d, $list)),
        ]);
    }
}
