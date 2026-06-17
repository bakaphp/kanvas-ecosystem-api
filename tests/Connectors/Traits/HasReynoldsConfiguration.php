<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;

/**
 * Sets fake Reynolds credentials on a company so the Client / Handler /
 * Actions can be exercised without touching the real R&R sandbox.
 *
 * If you need an actual round-trip against the sandbox, the live values
 * live in REYNOLDS_TEST_* env vars and the test that needs them must
 * read them explicitly and call markTestSkipped() when they are absent.
 */
trait HasReynoldsConfiguration
{
    protected function setupReynoldsConfiguration(AppInterface $app, Companies $company): void
    {
        $company->set(ConfigurationEnum::REYNOLDS_USERNAME->value, getenv('REYNOLDS_TEST_USERNAME') ?: 'test_user');
        $company->set(ConfigurationEnum::REYNOLDS_PASSWORD->value, getenv('REYNOLDS_TEST_PASSWORD') ?: 'test_pass');
        $company->set(
            ConfigurationEnum::REYNOLDS_ENDPOINT->value,
            getenv('REYNOLDS_TEST_ENDPOINT') ?: 'https://b2b-test.example.invalid/Sync/RCI/SalesAssistCRM/Receive.ashx'
        );
        $company->set(ConfigurationEnum::REYNOLDS_SENDER_NAME->value, 'SalesAssist');
        $company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, getenv('REYNOLDS_TEST_DEALER_NUMBER') ?: 'TESTDEALER001');
        $company->set(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value, '02');
        $company->set(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value, '01');
        $company->set(ConfigurationEnum::REYNOLDS_BUSINESS_UNIT_NAME->value, 'Reynolds Test Dealership');
    }
}
