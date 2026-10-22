<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Своя капча (v4.0).
 *
 * Почему не Google и не hCaptcha. Это провайдер идентичности: внешняя капча
 * означала бы третью сторону на экране входа и запрос к чужому домену с каждой
 * попытки — то есть чужой скрипт ровно там, где вводят пароль. Плюс пришлось бы
 * открывать CSP, которая сейчас `default-src 'self'` и это её главная ценность.
 *
 * Почему порог, а не «включить». Человек, который ввёл пароль верно с первого
 * раза, разгадывать картинки не должен: капча — ответ на подбор, а не пошлина
 * со всех подряд. Счётчик неудач ведётся по IP в rate_limits, а не в сессии:
 * бот, который чистит cookie, обошёл бы сессионный счётчик не заметив.
 *
 * Задача арифметическая, а не «прочитайте буквы»: цифры не путаются между
 * языками, и человеку с плохим зрением проще разобрать «7 + 4», чем гнутое
 * «rn» против «m».
 */
final class Captcha
{
    private const SESSION_KEY = 'captcha';

    /**
     * Кто задаёт вопрос: своя картинка или сторонний виджет.
     *
     * «Своя» — по умолчанию и осознанно: она работает в установке без выхода в
     * интернет, ничего не рассказывает о посетителях третьей стороне и не
     * добавляет внешней зависимости в поток входа. Сторонний виджет лучше
     * ловит автоматику, но за это платит и тем, и другим, — поэтому выбор
     * владельца, а не наше решение за него.
     */
    public const PROVIDERS = [
        'own' => ['Своя картинка', 'Работает без интернета наружу, не рассказывает о посетителях никому'],
        'turnstile' => ['Cloudflare Turnstile', 'Обычно без задач: проверяет поведение, а не «найдите светофоры»'],
        'hcaptcha' => ['hCaptcha', 'Задачи с картинками, работает в большинстве сетей'],
    ];

    /** Адреса проверки: оба провайдера принимают одинаковый POST. */
    private const VERIFY_URL = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'hcaptcha' => 'https://api.hcaptcha.com/siteverify',
    ];

    public static function enabled(): bool
    {
        return Setting::get('captcha.enabled') === '1';
    }

    /** Выбранный провайдер; неизвестное значение считаем своей картинкой. */
    public static function provider(): string
    {
        $p = (string)Setting::get('captcha.provider', 'own');
        return isset(self::PROVIDERS[$p]) ? $p : 'own';
    }

    /** Ключ виджета — он публичный и уезжает в разметку. */
    public static function siteKey(): string
    {
        return trim((string)Setting::get('captcha.site_key', ''));
    }

    /**
     * Сторонний виджет настроен? Без ключей он показал бы пустую рамку и
     * запер вход всем, поэтому непонятое состояние трактуем как «своя».
     */
    public static function widgetReady(): bool
    {
        return self::provider() !== 'own'
            && self::siteKey() !== ''
            && trim((string)Setting::get('captcha.secret', '')) !== '';
    }

    /** Сколько неудачных попыток терпим до картинки. */
    public static function afterAttempts(): int
    {
        return max(1, Setting::getInt('captcha.after_attempts', 3));
    }

    /** Публичная: значение показывается и задаётся в настройках (v4.14). */
    public static function ttl(): int
    {
        return max(60, min(3600, Setting::getInt('captcha.ttl', 300)));
    }

    /** Ключ счётчика неудач для текущего IP. */
    private static function failBucket(): string
    {
        return 'captcha:fail:' . RateLimiter::ipKey();
    }

    /** Неудачная попытка входа: приблизила к порогу. */
    public static function noteFailure(): void
    {
        if (!self::enabled()) return;
        /* Окно совпадает с жизнью капчи: спустя него счётчик обнуляется сам,
           и вчерашние опечатки не преследуют человека сегодня. */
        RateLimiter::hit(self::failBucket(), PHP_INT_MAX, self::ttl() * 4);
    }

    /** Успешный вход обнуляет счётчик — иначе капча оставалась бы навсегда. */
    public static function clearFailures(): void
    {
        try {
            DB::run('DELETE FROM {rate_limits} WHERE bucket = ?', [self::failBucket()]);
        } catch (\Throwable) {
            /* Таблицы может не быть на отстающей схеме — молча, это не критично */
        }
    }

    /** Нужна ли капча прямо сейчас на форме входа. */
    public static function requiredForLogin(): bool
    {
        if (!self::enabled()) return false;
        return self::failures() >= self::afterAttempts();
    }

    public static function requiredForRegister(): bool
    {
        return self::enabled() && Setting::get('captcha.on_register') === '1';
    }

    public static function requiredForRecover(): bool
    {
        return self::enabled() && Setting::get('captcha.on_recover') === '1';
    }

    private static function failures(): int
    {
        try {
            $row = DB::row('SELECT points, window_start FROM {rate_limits} WHERE bucket = ?',
                [self::failBucket()]);
        } catch (\Throwable) {
            return 0;
        }
        if (!$row) return 0;
        if (strtotime($row['window_start'] . ' UTC') + self::ttl() * 4 <= time()) return 0;
        return (int)$row['points'];
    }

    /**
     * Завести новую задачу и запомнить ответ.
     * В сессии лежит хэш, а не число: содержимое сессии переживает больше
     * рук, чем хотелось бы, и подсматривать ответ в ней не должно быть смысла.
     */
    public static function issue(): string
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $_SESSION[self::SESSION_KEY] = [
            'q' => "$a + $b",
            'h' => hash('sha256', (string)($a + $b)),
            't' => time(),
        ];
        return "$a + $b";
    }

    /** Текст текущей задачи; если её нет или протухла — заводим новую. */
    public static function question(): string
    {
        $c = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($c) || (int)($c['t'] ?? 0) < time() - self::ttl()) return self::issue();
        return (string)$c['q'];
    }

    /**
     * Проверить ответ. Задача одноразовая: верный ответ гасит её, чтобы
     * подставленную пару «вопрос-ответ» нельзя было переиспользовать в цикле.
     */
    public static function verify(string $answer): bool
    {
        if (self::widgetReady()) return self::verifyWidget();

        $c = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (!is_array($c) || (int)($c['t'] ?? 0) < time() - self::ttl()) return false;
        return hash_equals((string)$c['h'], hash('sha256', trim($answer)));
    }

    /**
     * Проверка стороннего виджета: токен из формы отдаём провайдеру.
     *
     * Ответ провайдера обязателен. Если он недоступен — считаем проверку
     * непройденной, а не пройденной: капча появляется после неудачных попыток
     * или с подозрительного узла, и «сервис молчит, поэтому пускаем» отменяет
     * ровно ту защиту, ради которой её включили. Вход при этом не запирается
     * насовсем — своя картинка остаётся запасным вариантом в настройках.
     */
    private static function verifyWidget(): bool
    {
        $field = self::provider() === 'turnstile' ? 'cf-turnstile-response' : 'h-captcha-response';
        $token = trim((string)($_POST[$field] ?? ''));
        if ($token === '') return false;

        $ch = curl_init(self::VERIFY_URL[self::provider()]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => (string)Setting::get('captcha.secret', ''),
                'response' => $token,
                'remoteip' => request_ip(),
            ]),
            /* Короткий таймаут: это шаг входа, и человек ждёт. Пять секунд —
               граница, после которой проверка сама становится проблемой. */
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $err !== '') {
            Log::warning('captcha.verify_unreachable', ['provider' => self::provider(), 'error' => $err]);
            return false;
        }
        $data = json_decode($body, true);
        return is_array($data) && ($data['success'] ?? false) === true;
    }

    /**
     * Картинка с задачей. Буквы не рисуем — только цифры и знак «+», поэтому
     * встроенного растрового шрифта GD достаточно и TTF в поставку тащить не
     * приходится (шрифты проекта лежат в woff2, GD их не читает).
     */
    public static function png(string $question): string
    {
        $w = 150; $h = 48;
        $im = imagecreatetruecolor($w, $h);
        $bg = (int)imagecolorallocate($im, 245, 245, 247);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);

        /* Шум до текста: линии поверх цифр мешали бы читать человеку не меньше,
           чем машине, а смысл капчи — быть трудной только для второй. */
        for ($i = 0; $i < 6; $i++) {
            $c = (int)imagecolorallocate($im, random_int(190, 225), random_int(190, 225), random_int(190, 225));
            imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }

        $fg = (int)imagecolorallocate($im, 30, 30, 40);
        $font = 5;
        $x = 16;
        foreach (str_split($question) as $ch) {
            if ($ch === ' ') { $x += 6; continue; }
            /* Каждый знак чуть смещён по вертикали: ровная строка распознаётся
               заметно легче. */
            imagestring($im, $font, $x, (int)($h / 2 - 8 + random_int(-5, 5)), $ch, $fg);
            $x += 14;
        }
        imagestring($im, $font, $x + 8, (int)($h / 2 - 8), '= ?', $fg);

        ob_start();
        imagepng($im);
        $png = (string)ob_get_clean();
        imagedestroy($im);
        return $png;
    }
}
