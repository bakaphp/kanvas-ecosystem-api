<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Amplitude;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Amplitude\Client;
use Tests\TestCase;

final class DownloadEventTest extends TestCase
{
    public function testDownloadEvent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set('amplitude_api_key', getenv('TEST_AMPLITUDE_KEY'));
        $app->set('amplitude_api_secret', getenv('TEST_AMPLITUDE_SECRET'));
        $amplitudeClient = new Client($app, $company);

        $startDate = date('Ymd\TH', strtotime('2024-10-17')) . '22';  // Start at 22:00 of the previous day
        $endDate = date('Ymd\TH', strtotime('2024-10-17')) . '23';    // End at 23:59 of the previous day

        $results = $amplitudeClient->eventsExport($startDate, $endDate);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('event_properties', $results[0]);
    }
}
