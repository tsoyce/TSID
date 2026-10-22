<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Роутер: точные пути + плейсхолдеры {id}. Флаги маршрута:
 *  'api'   — без CSRF/Origin-проверки (OAuth-endpoints с собственной аутентификацией)
 *  'auth'  — требуется вход
 *  'admin' — требуется роль admin/superadmin/client_admin
 */
final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler, array $flags = []): void
    {
        $this->routes[] = [strtoupper($method), $path, $handler, $flags];
    }

    public function get(string $path, callable|array $h, array $f = []): void { $this->add('GET', $path, $h, $f); }
    public function post(string $path, callable|array $h, array $f = []): void { $this->add('POST', $path, $h, $f); }

    /** Ищет маршрут: [handler, params, flags] или null. */
    public function match(string $method, string $uri): ?array
    {
        $uri = rtrim($uri, '/') ?: '/';
        foreach ($this->routes as [$m, $path, $handler, $flags]) {
            if ($m !== $method && !($m === 'GET' && $method === 'HEAD')) continue;
            if ($path === $uri) return [$handler, [], $flags];
            if (!str_contains($path, '{')) continue;
            $regex = '~^' . preg_replace('~\{(\w+)\}~', '(?P<$1>[^/]+)', $path) . '$~';
            if (preg_match($regex, $uri, $mm)) {
                $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
                return [$handler, array_map('urldecode', $params), $flags];
            }
        }
        return null;
    }

    public function dispatch(string $method, string $uri): void
    {
        $match = $this->match($method, $uri);
        if ($match === null) {
            ErrorPage::render(404);
        }
        [$handler, $params, $flags] = $match;

        // Защита форм: CSRF + Origin на всех POST, кроме API-маршрутов
        if ($method === 'POST' && !in_array('api', $flags, true)) {
            Csrf::verifyRequest();
        }
        if (in_array('auth', $flags, true) && !Auth::check()) {
            $_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/';
            redirect('/login');
        }

        /* Замок (v4.20). Заперто — значит, один экран вместо всех остальных.
           Список исключений короткий и назван поимённо: сам экран замка, выход
           и обмен с ключом устройства, которым и отпирают. Всё остальное, что
           требует входа, ждёт. Маршруты БЕЗ флага `auth` замок не трогает —
           это гостевые экраны и протокольные точки, у них своя проверка. */
        /* Флагов, требующих входа, ДВА: `auth` у кабинета и `admin` у админки.
           Первая версия смотрела только на `auth` — и замок не запирал ровно ту
           половину системы, где цена чужих рук выше всего. Поймано смоуком:
           админка после часа простоя открывалась как ни в чём не бывало. */
        if (Auth::locked() && (in_array('auth', $flags, true) || in_array('admin', $flags, true))) {
            $path = (string)parse_url($uri, PHP_URL_PATH);
            $open = ['/lock', '/lock/pin', '/logout',
                     '/passkey/login/options', '/passkey/login'];
            if (!in_array($path, $open, true)) {
                if ($method !== 'GET') {
                    /* POST в запертую сессию не возвращаем на экран замка с
                       потерей данных: он просто не выполняется. */
                    ErrorPage::render(423);
                }
                $_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/profile';
                redirect('/lock');
            }
        }
        if (in_array('admin', $flags, true)) {
            Auth::requireAdminArea();
        }

        if (is_array($handler)) {
            [$class, $methodName] = $handler;
            $handler = [new $class(), $methodName];
        }
        $handler(...array_values($params));
    }
}
