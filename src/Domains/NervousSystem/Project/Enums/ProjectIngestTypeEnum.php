<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Enums;

/**
 * The kinds of inbound context a project can receive through its single ingest funnel (webhook or the
 * explicit attach mutation) — each lands as a typed message on a project channel.
 */
enum ProjectIngestTypeEnum: string
{
    case TRANSCRIPT = 'transcript';
    case EMAIL = 'email';
    case MENTION = 'mention';

    public function messageVerb(): string
    {
        return match ($this) {
            self::TRANSCRIPT => 'meeting-transcript',
            self::EMAIL => 'project-email',
            self::MENTION => 'project-mention',
        };
    }

    /**
     * What a person calls this kind of signal. Notes, agent instructions and routing logs are worded
     * from it, so a new kind reads correctly everywhere without touching the routing code.
     */
    public function noun(): string
    {
        return match ($this) {
            self::TRANSCRIPT => 'meeting',
            self::EMAIL => 'email',
            self::MENTION => 'mention',
        };
    }

    public function bodyLabel(): string
    {
        return match ($this) {
            self::TRANSCRIPT => 'transcript',
            self::EMAIL => 'body',
            self::MENTION => 'message',
        };
    }
}
