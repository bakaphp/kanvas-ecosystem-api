<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LicensePlateExtractor\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\LicensePlateExtractor\DataTransferObject\LicensePlate;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ConfigurationEnum;
use Kanvas\Connectors\LicensePlateExtractor\Enums\CustomFieldEnum;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ProviderEnum;
use Kanvas\Connectors\LicensePlateExtractor\Services\PlateExtractionService;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Filesystem\Models\Filesystem;

class ExtractLicensePlateAction
{
    public function __construct(
        protected readonly Filesystem $filesystem,
        protected readonly AppInterface $app,
        protected readonly ?CompanyInterface $company = null,
        protected readonly ?ProviderEnum $providerOverride = null,
    ) {
    }

    public function execute(): array
    {
        if (! $this->isSupportedImage()) {
            return [
                'extracted' => false,
                'reason' => 'unsupported_file_type',
                'file_type' => $this->filesystem->file_type,
            ];
        }

        if (empty($this->filesystem->url)) {
            return [
                'extracted' => false,
                'reason' => 'missing_url',
            ];
        }

        $service = new PlateExtractionService(
            $this->app,
            $this->company ?? $this->filesystem->company,
            $this->providerOverride,
        );

        $result = $service->extract($this->filesystem->url);
        $provider = $this->providerOverride ?? $service->resolveProvider();

        if ($result === null) {
            $this->filesystem->set(CustomFieldEnum::EXTRACTION_STATUS->value, 'no_plate_detected');
            $this->filesystem->set(CustomFieldEnum::EXTRACTION_PROVIDER->value, $provider->value);

            return [
                'extracted' => false,
                'reason' => 'no_plate_detected',
                'provider' => $provider->value,
            ];
        }

        if (! $this->meetsConfidenceThreshold($result)) {
            $this->filesystem->set(CustomFieldEnum::EXTRACTION_STATUS->value, 'low_confidence');
            $this->filesystem->set(CustomFieldEnum::EXTRACTION_PROVIDER->value, $provider->value);
            $this->filesystem->set(CustomFieldEnum::PLATE_CONFIDENCE->value, (string) $result->confidence);

            return [
                'extracted' => false,
                'reason' => 'low_confidence',
                'plate_number' => $result->plateNumber,
                'confidence' => $result->confidence,
                'provider' => $provider->value,
            ];
        }

        $this->persist($result);

        return [
            'extracted' => true,
            'plate_number' => $result->plateNumber,
            'region' => $result->region,
            'confidence' => $result->confidence,
            'make' => $result->make,
            'model' => $result->model,
            'color' => $result->color,
            'type' => $result->type,
            'provider' => $provider->value,
        ];
    }

    private function isSupportedImage(): bool
    {
        return MediaTypeEnum::fromExtension($this->filesystem->file_type)->isImage();
    }

    private function meetsConfidenceThreshold(LicensePlate $result): bool
    {
        $threshold = (float) ($this->app->get(ConfigurationEnum::MIN_CONFIDENCE->value) ?? 0.0);

        return $result->confidence >= $threshold;
    }

    private function persist(LicensePlate $result): void
    {
        $this->filesystem->set(CustomFieldEnum::PLATE_NUMBER->value, $result->plateNumber);
        $this->filesystem->set(CustomFieldEnum::PLATE_CONFIDENCE->value, (string) $result->confidence);
        $this->filesystem->set(CustomFieldEnum::EXTRACTION_PROVIDER->value, $result->provider->value);
        $this->filesystem->set(CustomFieldEnum::EXTRACTION_STATUS->value, 'success');

        if ($result->region !== null) {
            $this->filesystem->set(CustomFieldEnum::PLATE_REGION->value, $result->region);
        }
        if ($result->make !== null) {
            $this->filesystem->set(CustomFieldEnum::VEHICLE_MAKE->value, $result->make);
        }
        if ($result->model !== null) {
            $this->filesystem->set(CustomFieldEnum::VEHICLE_MODEL->value, $result->model);
        }
        if ($result->color !== null) {
            $this->filesystem->set(CustomFieldEnum::VEHICLE_COLOR->value, $result->color);
        }
        if ($result->type !== null) {
            $this->filesystem->set(CustomFieldEnum::VEHICLE_TYPE->value, $result->type);
        }
        if (! empty($result->rawResponse)) {
            $this->filesystem->set(CustomFieldEnum::RAW_RESPONSE->value, json_encode($result->rawResponse));
        }
    }
}
