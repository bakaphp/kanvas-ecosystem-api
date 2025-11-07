<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\DataTransferObject\DealerSocket;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketConfigurationService;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;

trait HasDealerSockerConfiguration
{
    public function setupDealerSocketConfiguration(Companies $company, Apps $app, Regions $region): void
    {
        if (! env('TEST_DEALER_SOCKET_PUBLIC_KEY') ||
            ! env('TEST_DEALER_SOCKET_PRIVATE_KEY') ||
            ! env('TEST_DEALER_SOCKET_DEALER_ID') ||
            ! env('TEST_DEALER_SOCKET_USERNAME') ||
            ! env('TEST_DEALER_SOCKET_PASSWORD') ||
            ! env('TEST_DEALER_SOCKET_VENDOR_NAME')
        ) {
            throw new Exception('Missing Dealer Socket configuration');
        }

        DealerSocketConfigurationService::setup(new DealerSocket(
            company: $company,
            app: $app,
            region: $region,
            publicKey: env('TEST_DEALER_SOCKET_PUBLIC_KEY'),
            privateKey: env('TEST_DEALER_SOCKET_PRIVATE_KEY'),
            username: env('TEST_DEALER_SOCKET_USERNAME'),
            password: env('TEST_DEALER_SOCKET_PASSWORD'),
            dealerId: env('TEST_DEALER_SOCKET_DEALER_ID'),
            vendorName: env('TEST_DEALER_SOCKET_VENDOR_NAME')
        ));
    }

    public function setupDealerSocketIntegration(Companies $company, Regions $region)
    {
        $credentials = [
            'publicKey' => env('TEST_DEALER_SOCKET_PUBLIC_KEY'),
            'privateKey' => env('TEST_DEALER_SOCKET_PRIVATE_KEY'),
            'username' => env('TEST_DEALER_SOCKET_USERNAME'),
            'password' => env('TEST_DEALER_SOCKET_PASSWORD'),
            'dealerId' => env('TEST_DEALER_SOCKET_DEALER_ID'),
            'vendorName' => env('TEST_DEALER_SOCKET_VENDOR_NAME'),
        ];

        $integration = Integrations::first();

        $integrationDto = new IntegrationsCompany(
            integration: $integration,
            region: $region,
            company: $company,
            config: $credentials,
            app: app(Apps::class)
        );

        $status = Status::where('slug', StatusEnum::ACTIVE->value)
        ->where('apps_id', 0)
        ->first();

        return (new CreateIntegrationCompanyAction($integrationDto, auth()->user(), $status))->execute();
    }
}
