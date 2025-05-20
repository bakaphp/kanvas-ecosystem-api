<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Connectors\Apollo\Handlers\ApolloHandler;
use Kanvas\Connectors\Apollo\Workflows\Activities\ScreeningPeopleActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class PeopleScreeningTest extends TestCase
{
    use HasIntegrationCompany;

    public function testPeopleScreening(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app->set(ConfigurationEnum::APOLLO_API_KEY->value, getenv('TEST_APOLLO_KEY'));

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $company = $people->company;

        $this->setIntegration(
            $app,
            IntegrationsEnum::APOLLO,
            ApolloHandler::class,
            $company,
            $user
        );

        $activity = new ScreeningPeopleActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($people, $app, ['company' => $company]);

        $this->assertSame('success', $result['status']);
        $this->assertSame($people->getId(), $result['people_id']);
    }
}
