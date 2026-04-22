<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LicensePlateExtractor\Drivers;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\LicensePlateExtractor\Contracts\PlateExtractorDriverInterface;
use Kanvas\Connectors\LicensePlateExtractor\DataTransferObject\LicensePlate;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ConfigurationEnum;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ProviderEnum;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Override;
use RuntimeException;
use Throwable;

use function Laravel\Ai\agent;

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
        $prompt = <<<'PROMPT'
You are analyzing a photograph to extract license plate information.
Read the plate characters exactly as shown; do not guess obscured characters.
If no license plate is visible, return an empty plate_number with confidence 0.
Only report a region if the plate format or the visible jurisdiction text clearly identifies it.
PROMPT;

        $model = $this->app->get(ConfigurationEnum::MODEL->value) ?: self::DEFAULT_MODEL;

        try {
            $image = $this->downloadImageAsBase64($imageUrl);

            /** @var StructuredAgentResponse $response */
            $response = agent(
                schema: fn ($schema) => [
                    'plate_number' => $schema->string()->description('The license plate characters exactly as visible. Empty string if no plate visible.')->required(),
                    'confidence' => $schema->number()->description('Confidence score between 0 and 1 for the plate read.')->required(),
                    'region' => $schema->string()->description('Country or state code of the plate if identifiable (e.g. "do", "us-fl"). Empty if unknown.')->required(),
                    'make' => $schema->string()->description('Vehicle manufacturer if visible. Empty if unknown.')->required(),
                    'model' => $schema->string()->description('Vehicle model if visible. Empty if unknown.')->required(),
                    'color' => $schema->string()->description('Dominant exterior color. Empty if unknown.')->required(),
                    'type' => $schema->string()->description('Vehicle body type (sedan, suv, truck, motorcycle, etc). Empty if unknown.')->required(),
                ],
            )->prompt(
                $prompt,
                attachments: [$image],
                provider: Lab::Gemini,
                model: $model,
                timeout: 120,
            );
        } catch (AiException|Throwable $e) {
            report($e);

            return null;
        }

        $data = $response->structured;
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

    /**
     * Gemini rejects `fileUri` for non-Google-hosted URLs with "Invalid or unsupported file uri".
     * Download the bytes ourselves and send as base64 (`inlineData`) so S3 / CDN URLs work.
     */
    private function downloadImageAsBase64(string $imageUrl): Image
    {
        $response = Http::timeout(30)->get($imageUrl);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Failed to download image for plate extraction: ' . $imageUrl . ' (HTTP ' . (int) $response->status() . ')'
            );
        }

        $mime = $response->header('Content-Type') ?: 'image/jpeg';

        return Image::fromBase64(base64_encode($response->body()), $mime);
    }
}
