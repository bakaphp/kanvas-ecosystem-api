<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

enum MemoryCategoryEnum: string
{
    /** A fact about how the codebase is built. The most durable kind. */
    case ARCHITECTURE = 'architecture';

    /** Something that will trip up the next agent — the reason this table earns its keep. */
    case GOTCHA = 'gotcha';

    /** A choice a previous session made, and why. Stops the next one relitigating it. */
    case DECISION = 'decision';

    /** A house rule inferred from the code rather than from a written document. */
    case CONVENTION = 'convention';

    /**
     * What gets shown first when the budget is limited. A gotcha prevents damage; a decision only
     * prevents rework.
     */
    public function weight(): int
    {
        return match ($this) {
            self::GOTCHA => 4,
            self::ARCHITECTURE => 3,
            self::CONVENTION => 2,
            self::DECISION => 1,
        };
    }

    public function heading(): string
    {
        return match ($this) {
            self::GOTCHA => 'Watch out for',
            self::ARCHITECTURE => 'How this codebase is put together',
            self::CONVENTION => 'Conventions in this repository',
            self::DECISION => 'Decisions previous sessions made',
        };
    }
}
