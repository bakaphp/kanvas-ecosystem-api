<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LicensePlateExtractor\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use InvalidArgumentException;
use Kanvas\Connectors\LicensePlateExtractor\Contracts\PlateExtractorDriverInterface;
use Kanvas\Connectors\LicensePlateExtractor\DataTransferObject\LicensePlate;
use Kanvas\Connectors\LicensePlateExtractor\Drivers\GeminiDriver;
use Kanvas\Connectors\LicensePlateExtractor\Drivers\PlateRecognizerDriver;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ConfigurationEnum;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ProviderEnum;

class PlateExtractionService
{
    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null,
        protected ?ProviderEnum $providerOverride = null,
    ) {
    }

    public function extract(string $imageUrl): ?LicensePlate
    {
        return $this->driver()->extract($imageUrl);
    }

    public function resolveProvider(): ProviderEnum
    {
        if ($this->providerOverride !== null) {
            return $this->providerOverride;
        }

        $configured = $this->app->get(ConfigurationEnum::PROVIDER->value);

        return $configured !== null && $configured !== ''
            ? ProviderEnum::from((string) $configured)
            : ProviderEnum::GEMINI;
    }

    protected function driver(): PlateExtractorDriverInterface
    {
        return match ($this->resolveProvider()) {
            ProviderEnum::GEMINI => new GeminiDriver($this->app),
            ProviderEnum::PLATE_RECOGNIZER => new PlateRecognizerDriver($this->app, $this->company),
            default => throw new InvalidArgumentException('Unsupported license plate extractor provider'),
        };
    }
}
