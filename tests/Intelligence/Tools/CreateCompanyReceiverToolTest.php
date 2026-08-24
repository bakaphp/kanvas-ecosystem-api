<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\CreateCompanyReceiverTool;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Tests\TestCase;

final class CreateCompanyReceiverToolTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'workflow'];

    public function testCreatesAnEndpointAndReturnsTheUrlToPostTo(): void
    {
        $receiver = $this->anyReceiver();
        $name = 'Website contact form ' . fake()->unique()->uuid();

        $result = $this->tool(auth()->user())->__invoke(
            receiver: $receiver->name,
            name: $name,
            description: 'Posted to by the marketing site.',
        );

        $this->assertTrue($result['created'], $result['message'] ?? '');
        $this->assertSame($name, $result['name']);

        /** @var ReceiverWebhook $created */
        $created = ReceiverWebhook::query()->whereKey($result['receiver_id'])->first();

        $this->assertNotNull($created);
        $this->assertSame($receiver->getId(), (int) $created->action_id);
        $this->assertSame($this->company()->getId(), (int) $created->companies_id);
        $this->assertStringContainsString('/receiver/' . $created->uuid, $result['url']);
    }

    /**
     * The URL is the whole deliverable — an endpoint whose address nobody was told is useless, and
     * the person has to know it is bearer-like before pasting it somewhere.
     */
    public function testTheUrlComesBackWithAWarningThatItIsNotProtected(): void
    {
        $result = $this->tool(auth()->user())->__invoke(
            receiver: $this->anyReceiver()->name,
            name: 'Endpoint ' . fake()->unique()->uuid(),
        );

        $this->assertTrue($result['created']);
        $this->assertStringContainsString('not password', $result['message']);
    }

    public function testAWorkflowStepIsNotAcceptedAsAReceiver(): void
    {
        $step = Action::query()
            ->where('kind', ActionKindEnum::WORKFLOW->value)
            ->where('model_name', 'like', 'Kanvas%')
            ->where('is_deleted', 0)
            ->firstOrFail();

        $result = $this->tool(auth()->user())->__invoke(
            receiver: $step->name,
            name: 'Should not work ' . fake()->unique()->uuid(),
        );

        $this->assertFalse($result['created']);
        $this->assertArrayHasKey('suggested_receivers', $result);
    }

    public function testAnUnknownReceiverSuggestsRealOnes(): void
    {
        $result = $this->tool(auth()->user())->__invoke(
            receiver: 'NotARealReceiverJob',
            name: 'Nope ' . fake()->unique()->uuid(),
        );

        $this->assertFalse($result['created']);
        $this->assertNotEmpty($result['suggested_receivers']);
    }

    public function testConfigurationMustBeAJsonObject(): void
    {
        $result = $this->tool(auth()->user())->__invoke(
            receiver: $this->anyReceiver()->name,
            name: 'Bad config ' . fake()->unique()->uuid(),
            configuration: 'region_id=1',
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('JSON object', $result['message']);
    }

    /**
     * A public endpoint accepting data into the tenant is closer to handing out a credential than to
     * changing a setting.
     */
    public function testANonAdminCannotCreateAnEndpoint(): void
    {
        $before = ReceiverWebhook::query()->count();

        $result = $this->tool(Users::factory()->create())->__invoke(
            receiver: $this->anyReceiver()->name,
            name: 'Blocked ' . fake()->unique()->uuid(),
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('administrator', $result['message']);
        $this->assertSame($before, ReceiverWebhook::query()->count());
    }

    private function anyReceiver(): Action
    {
        $this->artisan('kanvas:workflow-sync-actions')->assertSuccessful();

        return Action::query()
            ->where('kind', ActionKindEnum::RECEIVER->value)
            ->where('model_name', 'like', 'Kanvas%')
            ->where('is_deleted', 0)
            ->firstOrFail();
    }

    private function tool(Users $requestingUser): CreateCompanyReceiverTool
    {
        return new CreateCompanyReceiverTool()
            ->withContext(app(Apps::class), $this->company(), auth()->user())
            ->forRequestingUser($requestingUser);
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }
}
