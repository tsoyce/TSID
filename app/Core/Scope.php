<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Область доступа: система → организация → сервис (v4.23).
 *
 * Объект-значение. Ничего не знает про базу и ни к чему не ходит: это пара
 * «тип + идентификатор» и одно отношение между такими парами — покрытие.
 *
 *     system  ⊇  org:X  ⊇  client:C     (если C принадлежит X)
 *
 * Из покрытия выводится всё остальное — какие роли действуют, какие права
 * складываются, какой уровень уезжает в токен. Отдельных правил для
 * организаций, отдельных для порталов и отдельных для системы больше нет:
 * есть одна таблица покрытия и три её строки.
 *
 * Почему объект, а не пара аргументов везде: пара «тип + id» без общего дома
 * разъезжается на первом же вызове — где-то `('org', 12)`, где-то
 * `(12, 'org')`, где-то строка `'org:12'`. Разбор строки и сборка обратно
 * тоже нужны в одном месте: область ездит в сессии, в ссылках и в журнале.
 *
 * Полный проект — SCOPES.md.
 */
final class Scope
{
    public const SYSTEM = 'system';
    public const ORG = 'org';
    public const CLIENT = 'client';

    /** @var list<string> Порядок от широкого к узкому — им же сортируем. */
    public const TYPES = [self::SYSTEM, self::ORG, self::CLIENT];

    private function __construct(
        public readonly string $type,
        public readonly ?int $id,
    ) {
    }

    public static function system(): self
    {
        return new self(self::SYSTEM, null);
    }

    public static function org(int $orgId): self
    {
        return new self(self::ORG, $orgId);
    }

    public static function client(int $clientDbId): self
    {
        return new self(self::CLIENT, $clientDbId);
    }

    /**
     * Область из пары значений — так она приезжает из базы.
     *
     * Неизвестный тип и область без идентификатора там, где он обязателен,
     * означают одно: запись испорчена. Возвращаем систему — самую широкую? Нет:
     * тихо расширить область значит тихо выдать доступ. Возвращаем самую узкую
     * из возможных — несуществующий портал, который не покрывает ничего.
     */
    public static function of(string $type, ?int $id): self
    {
        if ($type === self::SYSTEM) return self::system();
        if (($type === self::ORG || $type === self::CLIENT) && $id !== null && $id > 0) {
            return new self($type, $id);
        }
        return new self(self::CLIENT, 0);
    }

    /** Разбор строкового вида: `system`, `org:12`, `client:3`. */
    public static function parse(string $key): self
    {
        $key = trim($key);
        if ($key === '' || $key === self::SYSTEM) return self::system();
        $pos = strpos($key, ':');
        if ($pos === false) return self::of($key, null);
        return self::of(substr($key, 0, $pos), (int)substr($key, $pos + 1));
    }

    /** Строковый вид — для сессии, ссылок и журнала. */
    public function key(): string
    {
        return $this->id === null ? $this->type : $this->type . ':' . $this->id;
    }

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    /**
     * Покрывает ли эта область целевую.
     *
     * @param self     $target       что проверяем
     * @param int|null $targetOrgId  организация целевого портала — единственное,
     *        чего область о себе не знает и что приходится сообщать снаружи.
     *        Кто её выясняет, тот и отвечает за актуальность: смена
     *        организации у портала обязана менять ответ, а не подтверждать
     *        запомненный.
     */
    public function covers(self $target, ?int $targetOrgId = null): bool
    {
        /* Система покрывает всё — на то она и система. */
        if ($this->type === self::SYSTEM) return true;

        /* Точное совпадение: своя область покрывает саму себя. */
        if ($this->equals($target)) return true;

        /* Организация покрывает свои проекты. Ничей проект (org_id = NULL) не
           принадлежит никакой организации — и не покрывается ни одной. */
        if ($this->type === self::ORG && $target->type === self::CLIENT) {
            return $targetOrgId !== null && $targetOrgId === $this->id;
        }

        /* Портал не покрывает ни организацию, ни систему: снизу вверх
           полномочия не растут. */
        return false;
    }

    /**
     * Области, покрывающие портал, от широкой к узкой.
     *
     * Ровно тот список, по которому резолвер собирает роли: выдача действует
     * в портале тогда и только тогда, когда её область есть в этом списке.
     *
     * @return list<self>
     */
    public static function chainForClient(int $clientDbId, ?int $orgId): array
    {
        $chain = [self::system()];
        if ($orgId !== null && $orgId > 0) $chain[] = self::org($orgId);
        $chain[] = self::client($clientDbId);
        return $chain;
    }

    /** Подпись для интерфейса. Имена объектов резолвер подставляет сам. */
    public function label(): string
    {
        return match ($this->type) {
            self::SYSTEM => t('Вся система'),
            self::ORG => t('Организация'),
            default => t('Сервис'),
        };
    }
}
