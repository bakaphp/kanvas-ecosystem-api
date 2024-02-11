<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Workflows\ZohoLeadActivity;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

final class LeadActivityTest extends TestCase
{
    public function testLeadCreationWorkflow(): void
    {
        //use factory
        $lead = Lead::first();
        $lead->description = 'this is a test lead from github actions';
        $lead->saveOrFail();

        $lead->del('ZOHO_LEAD_ID');
        $company = $lead->company()->firstOrFail();
        $app = app(Apps::class);

        $app->set(FlagEnum::APP_GLOBAL_ZOHO->value, 1);
        $app->set(CustomFieldEnum::CLIENT_ID->value, getenv('TEST_ZOHO_CLIENT_ID'));
        $app->set(CustomFieldEnum::CLIENT_SECRET->value, getenv('TEST_ZOHO_CLIENT_SECRET'));
        $app->set(CustomFieldEnum::REFRESH_TOKEN->value, getenv('TEST_ZOHO_CLIENT_REFRESH_TOKEN'));

        $activity = new ZohoLeadActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $file = UploadedFile::fake()->createWithContent('test.txt', 'test');
        $filesystem = new FilesystemServices(app(Apps::class));
        $user = Auth::user();

        $lead->addFile(
            $filesystem->upload($file, $user),
            'test'
        );

        $result = $activity->execute($lead, $app, []);

        $zohoService = new ZohoService($app, $company);
        $zohoService->deleteLead($lead);
        
        $this->assertArrayHasKey('zohoLeadId', $result);
        $this->assertArrayHasKey('zohoRequest', $result);
        $this->assertArrayHasKey('leadId', $result);
    }
}
