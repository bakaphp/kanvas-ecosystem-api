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
        if (! getenv('TEST_LENDFLOW_API_KEY')) {
            $this->markTestSkipped('TEST_LENDFLOW_API_KEY not set');
        }
    }

    public function testUploadFilesToSandboxApplication(): void
    {
        $applicationId = getenv('TEST_LENDFLOW_APPLICATION_ID');
        if (! $applicationId) {
            $this->markTestSkipped('TEST_LENDFLOW_APPLICATION_ID not set (need a sandbox application id to upload to)');
        }

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $client = $this->getLendflowClient($app, $company);

        $tmp = tempnam(sys_get_temp_dir(), 'lendflow-test-');
        file_put_contents($tmp, "Lendflow connector integration test file.\n");

        try {
            $response = $client->uploadMultipart(
                '/api/v2/applications/' . $applicationId . '/files/multiple',
                [[
                    'name' => 'integration-test.txt',
                    'contents' => Utils::tryFopen($tmp, 'r'),
                ]]
            );

            $this->assertIsArray($response);
        } finally {
            @unlink($tmp);
        }
    }
}
