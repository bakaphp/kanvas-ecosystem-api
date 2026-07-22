<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Enums;

enum ProjectStatusEnum: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case ON_HOLD = 'on_hold';
    case BLOCKED = 'blocked';
    case DONE = 'done';
    case ARCHIVED = 'archived';
    case CANCELLED = 'cancelled';

    /**
     * Statuses where the project is still being worked — the heartbeat may tick these.
     *
     * @return array<int, self>
     */
    public static function openStatuses(): array
    {
        return [
            self::DRAFT,
            self::ACTIVE,
            self::ON_HOLD,
            self::BLOCKED,
        ];
    }

    /**
     * Statuses where the project won't change further.
     *
     * @return array<int, self>
     */
    public static function terminalStatuses(): array
    {
        return [
            self::DONE,
            self::ARCHIVED,
            self::CANCELLED,
        ];
    }

    /**
     * Like ::from() but tolerates common synonyms and case variations — agents and humans
     * naturally reach for these, so accept them rather than throw.
     */
    public static function fromAlias(string $value): self
    {
        $canonical = match (strtolower(trim($value))) {
            'completed', 'complete', 'finished', 'finish' => 'done',
            'canceled', 'cancel' => 'cancelled',
            'started', 'start', 'running', 'in_progress', 'in-progress' => 'active',
            'pending' => 'draft',
            'paused', 'hold', 'on-hold' => 'on_hold',
            'archive' => 'archived',
            default => strtolower(trim($value)),
        };

        return self::from($canonical);
    }
}
