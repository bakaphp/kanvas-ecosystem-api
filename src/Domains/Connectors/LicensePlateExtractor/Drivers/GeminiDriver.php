<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LicensePlateExtractor\Drivers;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\LicensePlateExtractor\Contracts\PlateExtractorDriverInterface;
use Kanvas\Connectors\LicensePlateExtractor\DataTransferObject\LicensePlate;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ConfigurationEnum;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ProviderEnum;
use Override;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Image;
use Throwable;

class GeminiDriver implements PlateExtractorDriverInterface
{
    private const string DEFAULT_MODEL = 'gemini-2.0-flash';

    public function __construct(
        protected AppInterface $app,
    ) {
    }

    #[Override]
    public function extract(string $imageUrl): ?LicensePlate
    {
        $schema = new ObjectSchema(
            name: 'license_plate_extraction',
            description: 'License plate and vehicle details extracted from a vehicle photo.',
            properties: [
                new StringSchema('plate_number', 'The license plate characters exactly as visible. Empty string if no plate visible.'),
                new NumberSchema('confidence', 'Confidence score between 0 and 1 for the plate read.'),
                new StringSchema('region', 'Country or state code of the plate if identifiable (e.g. "do", "us-fl"). Empty if unknown.'),
                new StringSchema('make', 'Vehicle manufacturer if visible. Empty if unknown.'),
                new StringSchema('model', 'Vehicle model if visible. Empty if unknown.'),
                new StringSchema('color', 'Dominant exterior color. Empty if unknown.'),
                new StringSchema('type', 'Vehicle body type (sedan, suv, truck, motorcycle, etc). Empty if unknown.'),
            ],
            requiredFields: ['plate_number', 'confidence', 'region', 'make', 'model', 'color', 'type'],
        );

        $prompt = <<<'PROMPT'
You are analyzing a photograph to extract license plate information.
Read the plate characters exactly as shown; do not guess obscured characters.
If no license plate is visible, return an empty plate_number with confidence 0.
Only report a region if the plate format or the visible jurisdiction text clearly identifies it.
PROMPT;

        $model = $this->app->get(ConfigurationEnum::MODEL->value) ?: self::DEFAULT_MODEL;

        try {
            $response = Prism::structured()
                ->using(Provider::Gemini, $model)
                ->withSchema($schema)
                ->withPrompt($prompt, [Image::fromUrl(url: $imageUrl)])
                ->withClientOptions([
                    'timeout' => 120,
                    'connect_timeout' => 30,
                ])
                ->asStructured();
        } catch (PrismException|Throwable $e) {
            report($e);

            return null;
        }

        $data = $response->structured ?? [];
        $plate = trim((string) ($data['plate_number'] ?? ''));

        if ($plate === '') {
            return null;
        }

        return new LicensePlate(
            plateNumber: $plate,
            provider: ProviderEnum::GEMINI,
            confidence: (float) ($data['confidence'] ?? 0.0),
            region: ! empty($data['region']) ? (string) $data['region'] : null,
            make: ! empty($data['make']) ? (string) $data['make'] : null,
            model: ! empty($data['model']) ? (string) $data['model'] : null,
            color: ! empty($data['color']) ? (string) $data['color'] : null,
            type: ! empty($data['type']) ? (string) $data['type'] : null,
            rawResponse: $data,
        );
    }
}
