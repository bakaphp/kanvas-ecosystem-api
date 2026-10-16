<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * A question the agent parked on, with the id needed to answer it.
 *
 * Carries the id for the same reason {@see HarnessPermissionRequest} does: without it a supervisor
 * learns a job is blocked and has no way to unblock it, and the session dies at the timeout.
 */
class HarnessQuestion extends Data
{
    /**
     * @param list<string> $fields Titles of the form's fields. More than one cannot be answered from a
     *                             single string, so the answer is refused rather than guessed at.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $fields = [],
    ) {
    }

    public function describe(): string
    {
        return $this->fields === []
            ? $this->title
            : $this->title . ' (' . implode(', ', $this->fields) . ')';
    }
}
