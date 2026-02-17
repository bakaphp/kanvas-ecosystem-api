<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite;
use Kanvas\Connectors\NetSuite\Services\NetSuiteServices;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;

trait HasNetSuiteConfiguration
{
    public function setupNetSuiteConfiguration(Companies $company, Apps $app): void
    {
        if (! env('NET_SUITE_ACCOUNT') || ! env('NET_SUITE_CONSUMER_KEY') || ! env('NET_SUITE_CONSUMER_SECRET') || ! env('NET_SUITE_TOKEN') || ! env('NET_SUITE_TOKEN_SECRET')) {
            throw new Exception('Missing NetSuite configuration');
        }

        $data = new NetSuite(
            app: $app,
            company: $company,
            account: env('NET_SUITE_ACCOUNT'),
            consumerKey: env('NET_SUITE_CONSUMER_KEY'),
            consumerSecret: env('NET_SUITE_CONSUMER_SECRET'),
            token: env('NET_SUITE_TOKEN'),
            tokenSecret: env('NET_SUITE_TOKEN_SECRET')
        );

        NetSuiteServices::setup($data);
    }

    public function setupNetSuiteIntegration(Companies $company, Regions $region)
    {
        $credentials = [
            'account' => env('NET_SUITE_ACCOUNT'),
            'consumer_key' => env('NET_SUITE_CONSUMER_KEY'),
            'consumer_secret' => env('NET_SUITE_CONSUMER_SECRET'),
            'token' => env('NET_SUITE_TOKEN'),
            'token_secret' => env('NET_SUITE_TOKEN_SECRET'),
        ];

        $integration = Integrations::firstOrCreate([
            'apps_id' => app(Apps::class)->getId(),
            'name' => 'netsuite',
        ], [
            'config' => [],
            'handler' => 'NetSuite',
        ]);

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
