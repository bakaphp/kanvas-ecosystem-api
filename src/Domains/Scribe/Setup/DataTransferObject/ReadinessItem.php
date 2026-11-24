<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\DataTransferObject;

use Kanvas\Scribe\Setup\Enums\ReadinessRequirementEnum;
use Kanvas\Scribe\Setup\Enums\ReadinessStatusEnum;

final readonly class ReadinessItem
{
    public function __construct(
        public ReadinessRequirementEnum $requirement,
        public ReadinessStatusEnum $status,
        public string $detail,
        public ?string $fixBy = null,
        public ?string $fixHow = null,
        public bool $optional = false,
    ) {
    }

    public function isReady(): bool
    {
        return $this->status === ReadinessStatusEnum::READY;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->requirement->value,
            'label' => $this->requirement->label(),
            'status' => $this->status->value,
            'detail' => $this->detail,
            'fix_by' => $this->fixBy,
            'fix_how' => $this->fixHow,
            'optional' => $this->optional,
        ];
    }
}
