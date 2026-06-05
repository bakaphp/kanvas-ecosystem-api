<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Lendflow;

use GuzzleHttp\Psr7\Utils;
use Kanvas\Apps\Models\Apps;
use Tests\Connectors\Traits\HasLendflowConfiguration;
use Tests\TestCase;

final class UploadFilesTest extends TestCase
{
    use HasLendflowConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Lendflow integration tests are skipped in CI');
        }
        if ($this->lendflowApiKey() === null) {
            $this->markTestSkipped('TEST_LENDFLOW_API_KEY not set');
        }
    }

    public function testUploadFilesToSandboxApplication(): void
    {
        $applicationId = $this->lendflowApplicationId();
        if ($applicationId === null) {
            $this->markTestSkipped('TEST_LENDFLOW_APPLICATION_ID not set (need a sandbox application id to upload to)');
        }

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $client = $this->getLendflowClient($app, $company);

        // Lendflow rejects plain text; it accepts real document/image types (e.g. PNG/PDF).
        $tmp = tempnam(sys_get_temp_dir(), 'lendflow-test-');
        file_put_contents($tmp, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));

        try {
            $response = $client->uploadMultipart(
                '/api/v2/applications/' . $applicationId . '/files/multiple',
                [[
                    'name' => 'integration-test.png',
                    'contents' => Utils::tryFopen($tmp, 'r'),
                ]]
            );

            $this->assertIsArray($response);
        } finally {
            @unlink($tmp);
        }
    }
}
