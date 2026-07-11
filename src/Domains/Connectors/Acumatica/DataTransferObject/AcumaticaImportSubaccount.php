1<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Spatie\LaravelData\Data;

class AcumaticaImportSubaccount extends Data
{
    public const string SOURCE = 'acumatica';

    public function __construct(
        public readonly string $externalId,
        public readonly string $subCode,
        public readonly ?string $description,
        public readonly bool $isActive,
    ) {
    }

    /**
     * @param array<array-key, mixed> $row raw dbo.Sub row (PascalCase columns)
     */
    public static function fromRow(array $row): self
    {
        $description = trim((string) ($row['Description'] ?? ''));

        return new self(
            externalId: trim((string) ($row['SubID'] ?? '')),
            subCode: trim((string) ($row['SubCD'] ?? '')),
            description: $description !== '' ? $description : null,
            isActive: (bool) ($row['Active'] ?? true),
        );
    }
}
