<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LicensePlateExtractor\Contracts;

use Kanvas\Connectors\LicensePlateExtractor\DataTransferObject\LicensePlate;

interface PlateExtractorDriverInterface
{
    public function extract(string $imageUrl): ?LicensePlate;
}
