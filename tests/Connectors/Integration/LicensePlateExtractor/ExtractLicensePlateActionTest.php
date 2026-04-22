<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\LicensePlateExtractor;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\LicensePlateExtractor\Actions\ExtractLicensePlateAction;
use Kanvas\Connectors\LicensePlateExtractor\Enums\CustomFieldEnum;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ProviderEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

final class ExtractLicensePlateActionTest extends TestCase
{
    public function testExecuteExtractsPlateWithGeminiAndPersistsOnFilesystem(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $filesystem = $this->makeFilesystem(
            $app,
            $company,
            $user,
            'jpg',
            'https://example.com/car.jpg'
        );

        StructuredAnonymousAgent::fake([
            [
                'plate_number' => 'ABC1234',
                'confidence' => 0.92,
                'region' => 'do',
                'make' => 'Toyota',
                'model' => 'Corolla',
                'color' => 'white',
                'type' => 'sedan',
            ],
        ]);

        $result = new ExtractLicensePlateAction(
            filesystem: $filesystem,
            app: $app,
            company: $company,
            providerOverride: ProviderEnum::GEMINI,
        )->execute();

        $this->assertTrue($result['extracted']);
        $this->assertSame('ABC1234', $result['plate_number']);
        $this->assertSame('do', $result['region']);
        $this->assertSame(ProviderEnum::GEMINI->value, $result['provider']);

        $this->assertSame('ABC1234', $filesystem->get(CustomFieldEnum::PLATE_NUMBER->value));
        $this->assertSame('do', $filesystem->get(CustomFieldEnum::PLATE_REGION->value));
        $this->assertSame('Toyota', $filesystem->get(CustomFieldEnum::VEHICLE_MAKE->value));
        $this->assertSame('Corolla', $filesystem->get(CustomFieldEnum::VEHICLE_MODEL->value));
        $this->assertSame('white', $filesystem->get(CustomFieldEnum::VEHICLE_COLOR->value));
        $this->assertSame('sedan', $filesystem->get(CustomFieldEnum::VEHICLE_TYPE->value));
        $this->assertSame('success', $filesystem->get(CustomFieldEnum::EXTRACTION_STATUS->value));
        $this->assertSame(ProviderEnum::GEMINI->value, $filesystem->get(CustomFieldEnum::EXTRACTION_PROVIDER->value));
    }

    public function testExecuteSkipsUnsupportedFileTypes(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $filesystem = $this->makeFilesystem(
            $app,
            $company,
            $user,
            'pdf',
            'https://example.com/document.pdf'
        );

        $result = new ExtractLicensePlateAction(
            filesystem: $filesystem,
            app: $app,
            company: $company,
            providerOverride: ProviderEnum::GEMINI,
        )->execute();

        $this->assertFalse($result['extracted']);
        $this->assertSame('unsupported_file_type', $result['reason']);
        $this->assertNull($filesystem->get(CustomFieldEnum::PLATE_NUMBER->value));
    }

    public function testExecuteMarksNoPlateDetectedWhenGeminiReturnsEmpty(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $filesystem = $this->makeFilesystem(
            $app,
            $company,
            $user,
            'png',
            'https://example.com/empty.png'
        );

        StructuredAnonymousAgent::fake([
            [
                'plate_number' => '',
                'confidence' => 0.0,
                'region' => '',
                'make' => '',
                'model' => '',
                'color' => '',
                'type' => '',
            ],
        ]);

        $result = new ExtractLicensePlateAction(
            filesystem: $filesystem,
            app: $app,
            company: $company,
            providerOverride: ProviderEnum::GEMINI,
        )->execute();

        $this->assertFalse($result['extracted']);
        $this->assertSame('no_plate_detected', $result['reason']);
        $this->assertSame('no_plate_detected', $filesystem->get(CustomFieldEnum::EXTRACTION_STATUS->value));
    }

    private function makeFilesystem(
        Apps $app,
        $company,
        $user,
        string $fileType,
        string $url
    ): Filesystem {
        $filesystem = new Filesystem();
        $filesystem->apps_id = $app->getId();
        $filesystem->companies_id = $company->getId();
        $filesystem->users_id = $user->getId();
        $filesystem->name = 'test-' . uniqid() . '.' . $fileType;
        $filesystem->path = 'test/' . $filesystem->name;
        $filesystem->url = $url;
        $filesystem->file_type = $fileType;
        $filesystem->size = '1024';
        $filesystem->saveOrFail();

        return $filesystem;
    }
}
