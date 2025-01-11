<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Credit700;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplicant;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Connectors\Credit700\Services\CreditScoreService;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class CreditTest extends TestCase
{
    public function testCreditScore(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(ConfigurationEnum::ACCOUNT->value, getenv('TEST_700_CREDIT_ACCOUNT'));
        $app->set(ConfigurationEnum::PASSWORD->value, getenv('TEST_700_CREDIT_PASSWORD'));
        $app->set(ConfigurationEnum::CLIENT_ID->value, getenv('TEST_700_CREDIT_CLIENT_ID'));
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, getenv('TEST_700_CREDIT_CLIENT_SECRET'));

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $creditApplication = new CreditApplicant(
            $people->getName(),
            fake()->streetAddress,
            fake()->city,
            fake()->state,
            fake()->postcode,
            fake()->ssn
        );

        $creditScoreAction = new CreditScoreService($app);
        $creditScore = $creditScoreAction->getCreditScore($creditApplication, $user);

        $this->assertArrayHasKey('scores', $creditScore);
    }
}
