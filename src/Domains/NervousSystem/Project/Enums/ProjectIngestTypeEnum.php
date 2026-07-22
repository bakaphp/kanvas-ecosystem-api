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

    /**
     * The Social MessageType verb the ingested item is stored under.
     */
    public function messageVerb(): string
    {
        return match ($this) {
            self::TRANSCRIPT => 'meeting-transcript',
            self::EMAIL => 'project-email',
            self::MENTION => 'project-mention',
        };
    }
}
