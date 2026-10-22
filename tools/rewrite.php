<?php

declare(strict_types=1);

/**
 * Безопасная массовая правка файлов.
 *
 * Написано после того, как разовый скрипт замены угробил три файла макетов:
 * `preg_replace` не смог скомпилировать шаблон, вернул null, и этот null
 * немедленно уехал в `file_put_contents`. Файлы стали нулевой длины, и узнал
 * об этом я только когда следующая правка не нашла в них искомой строки.
 *
 * Здесь та же работа обставлена так, что подобное невозможно:
 *
 *  1. Результат callback'а обязан быть непустой строкой. null, '' и false —
 *     это ошибка вызывающего кода, а не «новое содержимое файла».
 *  2. Результат проверяется парсером PHP (для .php) — сломанный синтаксис
 *     не доедет до диска.
 *  3. Результат проверяется на дубли атрибутов в теге: наивная замена
 *     style= на class= легко даёт второй class, который браузер молча
 *     игнорирует, — правка «применилась», а эффекта нет.
 *  4. Запись — всё или ничего. Пока не проверены ВСЕ файлы, не пишется НИ ОДИН.
 *     Половина применённой правки хуже, чем неприменённая.
 *  5. Перед записью снимается копия в памяти; если запись упала на середине,
 *     уже записанное откатывается.
 *
 * Использование:
 *
 *   require __DIR__ . '/rewrite.php';
 *   $r = new Rewrite(['templates']);
 *   $r->skip('/emails/');                       // письма правим руками
 *   $r->apply(fn(string $src, string $path) => str_replace('было', 'стало', $src));
 *   echo $r->report();
 *
 * Запуск проверки без записи: Rewrite::dryRun() до apply().
 */
final class Rewrite
{
    /** @var list<string> */
    private array $roots;
    /** @var list<string> */
    private array $skip = [];
    /** @var list<string> */
    private array $extensions = ['php', 'css', 'js'];
    private bool $dry = false;

    /** @var array<string, array{before: int, after: int}> */
    private array $changed = [];
    /** @var list<string> */
    private array $errors = [];
    private int $scanned = 0;

    /** @param list<string> $roots каталоги для обхода */
    public function __construct(array $roots)
    {
        $this->roots = $roots;
    }

    /** Не трогать пути, содержащие подстроку. */
    public function skip(string $fragment): self
    {
        $this->skip[] = $fragment;
        return $this;
    }

    /** @param list<string> $ext */
    public function only(array $ext): self
    {
        $this->extensions = $ext;
        return $this;
    }

    /** Проверить и показать, что получилось бы, но ничего не писать. */
    public function dryRun(): self
    {
        $this->dry = true;
        return $this;
    }

    /**
     * @param callable(string $source, string $path): ?string $fn
     *        Возвращает новое содержимое или тот же текст, если менять нечего.
     * @return bool true, если правка применена целиком
     */
    public function apply(callable $fn): bool
    {
        $pending = [];

        foreach ($this->files() as $path) {
            $this->scanned++;
            $src = file_get_contents($path);
            if ($src === false) { $this->errors[] = "$path: не читается"; continue; }

            try {
                $out = $fn($src, $path);
            } catch (\Throwable $e) {
                $this->errors[] = "$path: callback упал — " . $e->getMessage();
                continue;
            }

            /* Предохранитель 1: пустой результат — это баг, а не содержимое */
            if ($out === null || $out === false || $out === '') {
                $this->errors[] = "$path: callback вернул пустоту — правка отменена целиком";
                continue;
            }
            if ($out === $src) continue;

            /* Предохранитель 2: синтаксис */
            $err = $this->syntaxError($path, $out);
            if ($err !== null) { $this->errors[] = "$path: $err"; continue; }

            /* Предохранитель 3: дубли атрибутов */
            $dup = $this->duplicateAttributes($out);
            if ($dup !== []) {
                $this->errors[] = "$path: дубль атрибута в теге — " . implode('; ', array_slice($dup, 0, 2));
                continue;
            }

            $pending[$path] = $out;
            $this->changed[$path] = ['before' => substr_count($src, "\n"), 'after' => substr_count($out, "\n")];
        }

        /* Предохранитель 4: всё или ничего */
        if ($this->errors !== []) return false;
        if ($this->dry || $pending === []) return true;

        /* Предохранитель 5: откат, если запись упала на середине */
        $backup = [];
        foreach ($pending as $path => $out) {
            $backup[$path] = (string)file_get_contents($path);
            if (file_put_contents($path, $out) === false) {
                foreach ($backup as $p => $was) file_put_contents($p, $was);
                $this->errors[] = "$path: запись не удалась, всё откачено";
                return false;
            }
        }
        return true;
    }

    public function report(): string
    {
        $out = "просмотрено файлов: {$this->scanned}\n";
        if ($this->errors !== []) {
            $out .= "\nПРАВКА НЕ ПРИМЕНЕНА, ошибок: " . count($this->errors) . "\n";
            foreach ($this->errors as $e) $out .= "  ✗ $e\n";
            return $out;
        }
        if ($this->changed === []) return $out . "изменений нет\n";
        $out .= ($this->dry ? "изменилось бы файлов: " : "изменено файлов: ") . count($this->changed) . "\n";
        foreach ($this->changed as $path => $n) {
            $delta = $n['after'] - $n['before'];
            $out .= sprintf("  %-52s %+d строк\n", $this->rel($path), $delta);
        }
        return $out;
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }

    /** @return \Generator<string> */
    private function files(): \Generator
    {
        foreach ($this->roots as $root) {
            if (!is_dir($root)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                if (!in_array($file->getExtension(), $this->extensions, true)) continue;
                $path = str_replace('\\', '/', $file->getPathname());
                foreach ($this->skip as $s) if (str_contains($path, $s)) continue 2;
                yield $path;
            }
        }
    }

    /** Проверка синтаксиса без запуска кода. */
    private function syntaxError(string $path, string $code): ?string
    {
        if (!str_ends_with($path, '.php')) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'rw') . '.php';
        file_put_contents($tmp, $code);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lines, $code2);
        @unlink($tmp);
        if ($code2 === 0) return null;
        return 'синтаксис — ' . trim(preg_replace('~ in .*$~m', '', implode(' ', $lines)) ?? 'ошибка');
    }

    /**
     * Дубли атрибутов внутри одного тега. Браузер берёт первый и молча
     * игнорирует второй — правка «есть», а эффекта нет.
     *
     * @return list<string>
     */
    private function duplicateAttributes(string $html): array
    {
        $bad = [];
        if (!preg_match_all('~<[a-zA-Z][^>]*>~s', $html, $m)) return [];
        foreach ($m[0] as $tag) {
            foreach (['class', 'id', 'style', 'name', 'href', 'for'] as $attr) {
                if (substr_count($tag, ' ' . $attr . '="') > 1) {
                    $bad[] = $attr . ' ×2 в ' . preg_replace('~\s+~', ' ', substr($tag, 0, 70));
                }
            }
        }
        return $bad;
    }

    private function rel(string $path): string
    {
        $cwd = str_replace('\\', '/', getcwd() ?: '');
        return $cwd !== '' && str_starts_with($path, $cwd) ? ltrim(substr($path, strlen($cwd)), '/') : $path;
    }
}
