<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Stores;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Enums\AgentMessageTypeEnum;
use Kanvas\Intelligence\Agents\Neuron\Stores\EntityRollupMessageStore;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Tests\TestCase;
use Tests\Traits\ReadsMessageContents;

class EntityRollupMessageStoreSummaryTest extends TestCase
{
    use ReadsMessageContents;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'social', 'crm'];

    public function testACustomerFacingRollupNeverReplaysTheAgentSummary(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $action = new CreateMessageAction(new MessageInput(
            app: $app,
            company: $company,
            user: $user,
            type: MessageTypeService::getOrCreate($app, AgentMessageTypeEnum::AGENT_SUMMARY->value),
            message: ['content' => 'THE-SUMMARY', 'from_ia' => true],
            is_public: 0,
        ));
        $action->runWorkflow = false;
        $action->execute()->addEntity($people);

        $customerFacing = new EntityRollupMessageStore(app: $app, company: $company, user: $user, entity: $people);
        $internal = new EntityRollupMessageStore(
            app: $app,
            company: $company,
            user: $user,
            entity: $people,
            includeInternal: true,
        );

        $this->assertNotContains('THE-SUMMARY', $this->contents($customerFacing->loadActive('thread')));
        $this->assertContains('THE-SUMMARY', $this->contents($internal->loadActive('thread')));
    }
}
