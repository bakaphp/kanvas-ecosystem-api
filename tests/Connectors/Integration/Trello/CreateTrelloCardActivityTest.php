<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Trello;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Trello\Activities\CreateTrelloCardActivity;
use Kanvas\Connectors\Trello\Enums\ConfigurationEnum;
use Kanvas\Connectors\Trello\Enums\CustomFieldEnum;
use Kanvas\Connectors\Trello\Handlers\TrelloHandler;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class CreateTrelloCardActivityTest extends TestCase
{
    use HasIntegrationCompany;

    public function testCreatesACardAndLinksItToTheEntity(): void
    {
        $this->registerIntegration();
        $this->company()->set(ConfigurationEnum::API_KEY->value, 'test-key');
        $this->company()->set(ConfigurationEnum::API_TOKEN->value, 'test-token');

        Http::fake([
            'api.trello.com/1/cards*' => Http::response(['id' => 'card1', 'name' => 'New Lead']),
        ]);

        $message = $this->makeMessage();

        $result = $this->activity()->execute($message, app(Apps::class), [
            'list_id' => 'list1',
            'name' => 'New Lead',
        ]);

        $this->assertTrue($result['created']);
        $this->assertSame('card1', $result['card']['id']);
        $this->assertSame('card1', $message->get(CustomFieldEnum::TRELLO_CARD_ID->value));
    }

    public function testUpdatesTheLinkedCardInsteadOfCreatingADuplicate(): void
    {
        $this->registerIntegration();
        $this->company()->set(ConfigurationEnum::API_KEY->value, 'test-key');
        $this->company()->set(ConfigurationEnum::API_TOKEN->value, 'test-token');

        $message = $this->makeMessage();
        $message->set(CustomFieldEnum::TRELLO_CARD_ID->value, 'card1');

        Http::fake([
            'api.trello.com/1/cards/card1*' => Http::response(['id' => 'card1', 'name' => 'Updated title']),
        ]);

        $result = $this->activity()->execute($message, app(Apps::class), [
            'list_id' => 'list1',
            'name' => 'Updated title',
        ]);

        $this->assertFalse($result['created']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function testFailsTheWorkflowWhenRequiredParamsAreMissing(): void
    {
        $result = $this->activity()->execute($this->makeMessage(), app(Apps::class), []);

        $this->assertStringContainsString('list_id', $result['message']);
    }

    private function activity(): CreateTrelloCardActivity
    {
        return new CreateTrelloCardActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), []);
    }

    private function registerIntegration(): void
    {
        $user = auth()->user();

        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::TRELLO,
            TrelloHandler::class,
            $user->getCurrentCompany(),
            $user
        );
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }

    private function makeMessage(): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company()->getId())
            ->create(['message' => ['content' => 'A new lead came in.']]);
    }
}
