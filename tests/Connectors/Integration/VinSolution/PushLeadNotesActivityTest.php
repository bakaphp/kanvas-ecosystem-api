<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Workflow\PushLeadNotesActivity;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\StoredWorkflow;
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
