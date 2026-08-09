<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WooCommerce;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WooCommerce\Webhooks\SyncExternalWooCommerceUserWebhookJob;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class SyncExternalWooCommerceUserWebhookJobTest extends TestCase
{
    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => SyncExternalWooCommerceUserWebhookJob::class],
            ['name' => 'SyncExternalWooCommerceUserWebhookJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    /**
     * Regression for KANVAS-ECOSYSTEM-3X8: an existing user (not yet in this app) synced
     * without a `password` in the payload must not throw "Undefined array key password".
     */
    public function testRegistersExistingUserInAppWithoutPasswordInPayload(): void
    {
        Queue::fake();

        $existingUser = Users::factory()->create();

        $payload = [
            'email' => $existingUser->email,
            'first_name' => $existingUser->firstname,
            'last_name' => $existingUser->lastname,
            'run_workflow' => false,
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertSame('success', $result['status']);
        $this->assertSame('User exists but was added to this app', $result['message']);

        $this->assertNotNull(
            UsersRepository::belongsToThisApp($existingUser, $this->receiver->app),
        );
    }

    private function dispatchWebhookJob(array $payload): ?array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload,
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        $job = new SyncExternalWooCommerceUserWebhookJob($webhookRequest);

        return $job->handle();
    }
}
