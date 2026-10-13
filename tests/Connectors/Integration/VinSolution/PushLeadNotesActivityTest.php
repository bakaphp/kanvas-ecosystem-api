<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Workflow\PushLeadNotesActivity;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Override;
use Tests\TestCase;

final class PushLeadNotesActivityTest extends TestCase
{
    public function testMessageAttachedToPeopleIsSkippedInsteadOfFatalling(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(ConfigurationEnum::COMPANY->value, '12345');

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $message = Message::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);
        $message->addEntity($people);

        $result = $this->makeActivity()->execute($message->fresh('appModuleMessage'), $app, []);

        $this->assertSame('Message is not associated with a Lead', $result['error'] ?? null);
    }

    public function testMessageLinkedToPeopleAndLeadPassesTheLeadGuard(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(ConfigurationEnum::COMPANY->value, '12345');

        /** @var Lead $lead */
        $lead = Lead::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);

        $message = Message::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);
        $message->addEntity(People::getById((int) $lead->people_id));
        $message->addEntity($lead);

        $activity = new class (
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            [],
        ) extends PushLeadNotesActivity {
            #[Override]
            public function executeIntegration(
                Model $entity,
                AppInterface $app,
                IntegrationsEnum $integration,
                callable $integrationOperation,
                array $additionalParams = [],
                ?Regions $region = null,
                ?Companies $company = null,
                bool $throwException = false
            ): array {
                return ['reached_integration' => true];
            }
        };

        $result = $activity->execute($message->fresh(), $app, []);

        $this->assertTrue($result['reached_integration'] ?? false);
    }

    private function makeActivity(): PushLeadNotesActivity
    {
        return new PushLeadNotesActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            [],
        );
    }
}
