<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Enums;

/**
 * Provider-agnostic kanban task status. Values match the Hermes vocabulary 1:1 (so a Hermes
 * board row maps with ::from), but the enum lives on the primary domain so the NervousSystem
 * sync layer never imports a connector. Other runtimes map their own statuses onto these cases.
 */
enum KanbanStatusEnum: string
{
    case TRIAGE = 'triage';
    case TODO = 'todo';
    case READY = 'ready';
    case RUNNING = 'running';
    case BLOCKED = 'blocked';
    case DONE = 'done';
    case ARCHIVED = 'archived';

    // Tolerant parse — unknown/empty status from a runtime falls back to TODO rather than fataling.
    public static function fromRuntime(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::TODO;
    }
}
