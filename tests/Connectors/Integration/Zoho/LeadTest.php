<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\DownloadAllZohoLeadAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Support\Setup;
use Tests\TestCase;

final class LeadTest extends TestCase
{
    public function testDownloadAllLeads(): void
    {
        $company = Companies::first();
        $app = app(Apps::class);
        $user = auth()->user();

        $app->set(FlagEnum::APP_GLOBAL_ZOHO->value, 1);
        $app->set(CustomFieldEnum::CLIENT_ID->value, getenv('TEST_ZOHO_CLIENT_ID'));
        $app->set(CustomFieldEnum::CLIENT_SECRET->value, getenv('TEST_ZOHO_CLIENT_SECRET'));
        $app->set(CustomFieldEnum::REFRESH_TOKEN->value, getenv('TEST_ZOHO_CLIENT_REFRESH_TOKEN'));

        $receiver = (new CreateLeadReceiverAction(
            new LeadReceiver(
                app: $app,
                branch: $company->branches()->first(),
                user: $user,
                agent: $user,
                name: 'test',
                source: 'test',
                isDefault: true
            )
        ))->execute();

        $companySetup = new Setup($app, $user, $company);
        $companySetup->run();

        $downloadAllLeads = new DownloadAllZohoLeadAction(
            $app,
            $company,
            $receiver
        );

        $leads = $downloadAllLeads->execute(totalPages: 1, leadsPerPage: 1);

        $this->assertIsArray(iterator_to_array($leads));
        $this->assertEquals(1, $downloadAllLeads->getTotalLeadsProcessed());
    }
}
