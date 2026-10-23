<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Парольная политика — конструктор в /admin/settings → Политики.
 * Всё опционально: каждое требование включается отдельно, по умолчанию — только длина.
 */
final class PasswordPolicy
{
    /** null — пароль проходит; иначе текст ошибки для пользователя. */
    public static function check(#[\SensitiveParameter] string $pw): ?string
    {
        $min = max(1, Setting::passwordMin());
        if (mb_strlen($pw) < $min) {
            return t('Пароль — от :n :word.', ['n' => $min, 'word' => t(plural($min, 'символа', 'символов', 'символов'))]);
        }
        if (Setting::get('policy.password_digits') === '1' && !preg_match('~\d~', $pw)) {
            return t('В пароле нужна хотя бы одна цифра.');
        }
        if (Setting::get('policy.password_letters') === '1' && !preg_match('~\p{L}~u', $pw)) {
            return t('В пароле нужна хотя бы одна буква.');
        }
        if (Setting::get('policy.password_upper') === '1'
            && (!preg_match('~\p{Lu}~u', $pw) || !preg_match('~\p{Ll}~u', $pw))) {
            return t('В пароле нужны буквы в обоих регистрах.');
        }
        if (Setting::get('policy.password_special') === '1' && !preg_match('~[^\p{L}\p{N}]~u', $pw)) {
            return t('В пароле нужен хотя бы один спецсимвол.');
        }
        return null;
    }

    /** Человеческая подсказка под полем: «от 8 символов, с цифрой и спецсимволом». */
    public static function hint(): string
    {
        $min = max(1, Setting::passwordMin());
        $parts = [t('от :n :word', ['n' => $min, 'word' => t(plural($min, 'символа', 'символов', 'символов'))])];
        if (Setting::get('policy.password_digits') === '1') $parts[] = t('с цифрой');
        if (Setting::get('policy.password_letters') === '1') $parts[] = t('с буквой');
        if (Setting::get('policy.password_upper') === '1') $parts[] = t('в обоих регистрах');
        if (Setting::get('policy.password_special') === '1') $parts[] = t('со спецсимволом');
        return implode(', ', $parts);
    }
}
