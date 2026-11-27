<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\DataTransferObject;

use Kanvas\Workflow\Integrations\Imports\Enums\ImportOutcomeEnum;

final class ImportChunkResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;
    public int $failed = 0;

    /** @var list<array{id: string, error: string}> */
    public array $errors = [];

    public function add(ImportOutcomeEnum $outcome): void
    {
        match ($outcome) {
            ImportOutcomeEnum::CREATED => $this->created++,
            ImportOutcomeEnum::UPDATED => $this->updated++,
            ImportOutcomeEnum::SKIPPED => $this->skipped++,
        };
    }

    public function fail(string $id, string $error): void
    {
        $this->failed++;
        $this->errors[] = ['id' => $id, 'error' => $error];
    }

    /**
     * @return array{created: int, updated: int, skipped: int, failed: int, errors: list<array{id: string, error: string}>}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'errors' => $this->errors,
        ];
    }
}
