<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Jobs\CreateLeadsFromRoutedReceiverJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

class CreateLeadsFromRoutedReceiverJobTest extends TestCase
{
    private ReceiverWebhook $receiver;
    private LeadReceiver $defaultReceiver;
    private LeadReceiver $creReceiver;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->defaultReceiver = $this->createLeadReceiver('LendingTree');
        $this->creReceiver = $this->createLeadReceiver('LendingTree CRE');

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => CreateLeadsFromRoutedReceiverJob::class],
            ['name' => 'CreateLeadsFromRoutedReceiverJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    'receiver_id' => $this->defaultReceiver->getId(),
                    'routes' => [
                        [
                            'field' => 'Loan_Type',
                            'equals' => 'Commercial Real Estate',
                            'receiver_id' => $this->creReceiver->getId(),
                        ],
                    ],
                ],
            ]);
    }

    public function testMatchingRouteCreatesTheLeadOnTheRoutedReceiver(): void
    {
        $result = $this->dispatchWebhookJob($this->payload(['loan_type' => ' commercial real estate ']));

        $this->assertEquals($this->creReceiver->getId(), $result['receiver']);
        $this->assertEquals($this->creReceiver->getId(), $this->receiverIdOfLead($result));
    }

    public function testUnmatchedValueFallsBackToTheDefaultReceiver(): void
    {
        $result = $this->dispatchWebhookJob($this->payload(['Loan_Type' => 'Working Capital']));

        $this->assertEquals($this->defaultReceiver->getId(), $result['receiver']);
        $this->assertEquals($this->defaultReceiver->getId(), $this->receiverIdOfLead($result));
    }

    public function testMissingFieldFallsBackToTheDefaultReceiver(): void
    {
        $result = $this->dispatchWebhookJob($this->payload());

        $this->assertEquals($this->defaultReceiver->getId(), $result['receiver']);
    }

    public function testContainsRouteMatchesInsideANestedField(): void
    {
        $this->receiver->update([
            'configuration' => [
                'receiver_id' => $this->defaultReceiver->getId(),
                'routes' => [
                    [
                        'field' => 'Other.Product',
                        'contains' => 'real estate',
                        'receiver_id' => $this->creReceiver->getId(),
                    ],
                ],
            ],
        ]);

        $result = $this->dispatchWebhookJob(
            $this->payload(['Other' => ['Product' => 'Commercial Real Estate Loan']])
        );

        $this->assertEquals($this->creReceiver->getId(), $result['receiver']);
    }

    public function testRouteIsReadFromADoubleEncodedPayload(): void
    {
        $this->receiver->update([
            'configuration' => array_merge(
                $this->receiver->configuration,
                ['double_encoded_json' => true],
            ),
        ]);

        $inner = json_encode($this->payload(['Loan_Type' => 'Commercial Real Estate']));

        $result = $this->dispatchWebhookJob([$inner => null]);

        $this->assertEquals($this->creReceiver->getId(), $result['receiver']);
    }

    private function receiverIdOfLead(array $result): int
    {
        return Lead::query()
            ->where('id', $result['lead_id'])
            ->value('leads_receivers_id');
    }

    private function payload(array $extra = []): array
    {
        return [
            'title' => fake()->title(),
            'people' => [
                'firstname' => 'John',
                'lastname' => 'Doe',
                'contacts' => [
                    [
                        'value' => fake()->uuid() . '@example.com',
                        'weight' => 0,
                        'contacts_types_id' => 1,
                    ],
                ],
            ],
            'pipeline_stage_id' => 0,
            ...$extra,
        ];
    }

    private function createLeadReceiver(string $name): LeadReceiver
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $leadType = LeadType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Test Type ' . Str::uuid(),
            'description' => 'Test',
            'is_active' => true,
        ]);

        $leadSource = LeadSource::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Test Source ' . Str::uuid(),
            'description' => 'Test',
            'is_active' => true,
            'leads_types_id' => $leadType->getId(),
        ]);

        return LeadReceiver::create([
            'name' => $name . ' ' . Str::uuid(),
            'users_id' => $user->getId(),
            'agents_id' => $user->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'leads_sources_id' => $leadSource->getId(),
            'lead_types_id' => $leadType->getId(),
            'source_name' => 'test-source',
            'is_default' => false,
        ]);
    }

    private function dispatchWebhookJob(array $payload): array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload,
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        Queue::fake();

        $result = new CreateLeadsFromRoutedReceiverJob($webhookRequest)->handle();

        if ($result === null) {
            $webhookRequest->refresh();
            $this->fail('Job handle() returned null: ' . json_encode($webhookRequest->exception ?? []));
        }

        return $result;
    }
}
