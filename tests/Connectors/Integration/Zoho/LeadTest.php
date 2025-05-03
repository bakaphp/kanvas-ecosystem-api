<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\DownloadAllZohoLeadAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Handlers\ZohoHandler;
use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Support\Setup;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class LeadTest extends TestCase
{
    use HasIntegrationCompany;
    
    public function testDownloadAllLeads(): void
    {
        $company = Companies::first();
        $app = app(Apps::class);
        $user = auth()->user();

        $this->setIntegration(
            $app,
            IntegrationsEnum::ZOHO,
            ZohoHandler::class,
            $company,
            $user
        );

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

        foreach ($leads as $lead) {
            $this->assertInstanceOf(Lead::class, $lead);
        }

        $this->assertEquals(1, $downloadAllLeads->getTotalLeadsProcessed());
    }
}
