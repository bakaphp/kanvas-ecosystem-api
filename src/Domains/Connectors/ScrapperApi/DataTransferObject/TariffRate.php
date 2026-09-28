<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\DataTransferObject;

use Kanvas\Connectors\ScrapperApi\Enums\ArancelSourceEnum;

final readonly class TariffRate
{
    public function __construct(
        public ?string $code,
        public int $rate,
        public bool $itbisExempt,
        public string $name,
        public ArancelSourceEnum $source = ArancelSourceEnum::CACHED,
    ) {
    }

    public function withSource(ArancelSourceEnum $source): self
    {
        return new self(
            code: $this->code,
            rate: $this->rate,
            itbisExempt: $this->itbisExempt,
            name: $this->name,
            source: $source,
        );
    }
}
