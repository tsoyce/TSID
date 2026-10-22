<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Правило имени входа — одно на все двери.
 *
 * До этого правило было ровно одно и жило в одном контроллере: человек, меняя
 * логин у себя в профиле, получал проверку `^[a-z0-9_.-]{3,32}$`. А через SCIM,
 * Admin API, импорт из файла и перенос людей с портала логин приезжал КАКОЙ
 * УГОДНО — проверялась только занятость. То есть провайдер мог завести `admin`,
 * ` ` или строку на двести знаков, и она становилась именем для входа.
 *
 * Разные двери в один дом обязаны проверять одинаково: если правило можно
 * обойти, зайдя с другой стороны, это не правило, а рекомендация.
 *
 * Что настраивается, а что нет:
 *  · длина — настраивается, у разных установок разные привычки;
 *  · набор символов — НЕТ. Логин уезжает в claim, в адреса, в чужие конфиги;
 *    разрешить там пробел или кириллицу значит однажды получить письмо «у нас
 *    не работает вход» с неотличимыми на вид «а» латинской и русской;
 *  · занятые имена — настраиваются, список свой у каждой установки.
 *
 * Пустой логин допустим везде: человек без логина входит по почте (v4.5).
 */
final class LoginName
{
    /** Границы длины, в которых можно настраивать. */
    public const MIN_FLOOR = 2;
    public const MAX_CEIL = 64;

    /**
     * Имена, которые нельзя занять по умолчанию.
     *
     * Не «на всякий случай»: каждое из них однажды становится адресом или
     * подписью в письме. Человек с логином `noreply` получает право выглядеть
     * почтой сервиса, а с логином `admin` — путать поддержку.
     */
    public const RESERVED_DEFAULT = [
        'admin', 'administrator', 'root', 'superuser', 'system', 'support',
        'help', 'security', 'abuse', 'postmaster', 'webmaster', 'hostmaster',
        'info', 'noreply', 'no-reply', 'mailer-daemon', 'api', 'oauth', 'sso',
        'id', 'me', 'you', 'null', 'undefined',
    ];

    /** Кириллица латиницей для логина из почты — как в загранпаспорте, без апострофов. */
    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'iu', 'я' => 'ia',
    ];

    public static function min(): int
    {
        return max(self::MIN_FLOOR, min(self::MAX_CEIL, Setting::getInt('policy.login_min', 3)));
    }

    public static function max(): int
    {
        return max(self::min(), min(self::MAX_CEIL, Setting::getInt('policy.login_max', 32)));
    }

    /** Занятые имена: свои поверх умолчания, по одному на строку или через запятую. */
    public static function reserved(): array
    {
        $raw = trim((string)Setting::get('policy.login_reserved', ''));
        if ($raw === '') return self::RESERVED_DEFAULT;
        $own = preg_split('~[\s,;]+~u', mb_strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_merge(self::RESERVED_DEFAULT, $own)));
    }

    /** Приведение к единому виду: регистр не должен создавать двойников. */
    public static function normalize(string $login): string
    {
        return mb_strtolower(trim($login));
    }

    /**
     * Проверка. Возвращает текст ошибки или null, если имя годится.
     *
     * Текст, а не bool: человеку надо сказать, что именно не так, а зовущему
     * коду — что показать. Возвращать «false» и составлять объяснение на месте
     * значит написать пять разных объяснений одного и того же.
     */
    public static function check(string $login): ?string
    {
        $login = self::normalize($login);
        if ($login === '') return null;             // без логина можно

        $len = mb_strlen($login);
        if ($len < self::min() || $len > self::max()) {
            return t('Логин — от :min до :max символов.', ['min' => self::min(), 'max' => self::max()]);
        }
        if (!preg_match('~^[a-z0-9_.-]+$~', $login)) {
            return t('В логине допустимы латинские буквы, цифры, точка, дефис и подчёркивание.');
        }
        /* Точка или дефис по краям даёт имя, которое ломается при подстановке в
           адрес и выглядит опечаткой в списке. */
        if (preg_match('~^[.\-_]|[.\-_]$~', $login)) {
            return t('Логин не может начинаться и заканчиваться точкой, дефисом или подчёркиванием.');
        }
        if (in_array($login, self::reserved(), true)) {
            return t('Это имя занято системой — выберите другое.');
        }
        return null;
    }

    /**
     * Логин нового аккаунта из почты (v4.72): всё до @.
     *
     * Без логина человек входит по почте, но на порталах и в списках его видно
     * по логину — и пустая колонка у каждого второго читалась как недосмотр.
     * Метка после плюса отбрасывается (ivan+shop → ivan): она про сортировку
     * писем, а не про имя. Недопустимое — кириллица, пробелы — заменяется
     * точкой, края чистятся, длина укладывается в настройки. Занятое имя
     * получает номер: ivan, ivan2, ivan3. Имя из списка занятых системой
     * (admin, support…) — тоже: admin2.
     *
     * @param callable(string): bool $taken занято ли имя
     */
    public static function fromEmail(string $email, callable $taken): ?string
    {
        $local = mb_strtolower(trim((string)strstr($email, '@', true)));
        $local = (string)preg_replace('~\+.*$~', '', $local);
        /* Кириллица — латиницей, а не точками: «иван.петров@почта.рф» даёт
           ivan.petrov, а не пустое имя. */
        $local = strtr($local, self::TRANSLIT);
        $local = (string)preg_replace('~[^a-z0-9_.-]+~', '.', $local);
        $local = (string)preg_replace('~([._-])[._-]+~', '$1', $local);
        $local = trim($local, '._-');
        if ($local === '') return null;
        $max = self::max();
        for ($n = 1; $n < 1000; $n++) {
            $suffix = $n === 1 ? '' : (string)$n;
            $base = rtrim(mb_substr($local, 0, $max - strlen($suffix)), '._-');
            /* Короче минимума — добиваем номером: «a» при минимуме 3 → «a01». */
            if (mb_strlen($base . $suffix) < self::min()) {
                $suffix = str_pad((string)$n, self::min() - mb_strlen($base), '0', STR_PAD_LEFT);
            }
            $candidate = $base . $suffix;
            if (self::check($candidate) === null && !$taken($candidate)) return $candidate;
        }
        return null;
    }

    /** Годится ли имя. Для мест, где объяснять некому (SCIM, импорт). */
    public static function ok(string $login): bool
    {
        return self::check($login) === null;
    }
}
