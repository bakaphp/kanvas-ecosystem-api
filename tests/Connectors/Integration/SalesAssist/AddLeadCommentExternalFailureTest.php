<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssist;

use Exception;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Activities\BaseAddLeadCommentFromAgentMessageActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Tests\TestCase;

final class AddLeadCommentExternalFailureTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['social', 'crm', 'ecosystem', 'workflow'];

    /**
     * A throw from the external CRM (e.g. a 401 when a tenant's credentials expire)
     * must be swallowed into a failWorkflow inside the closure, never propagate to
     * executeIntegration's catch — which would report() it to Sentry. We assert the
     * returned array is the failWorkflow shape (has 'lead', no 'trace'): the
     * executeIntegration catch path would instead add 'company'/'trace'.
     */
    public function testExternalSystemThrowBecomesFailWorkflowWithoutPropagating(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'note'],
            ['name' => 'Note']
        );

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => 'external-failure-test-channel',
            ],
            ['name' => 'Test channel', 'description' => 'Test channel', 'users_id' => auth()->user()->getId()]
        );

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => 'Sally AI: Hola, interested in a car', 'from_me' => false],
                'is_public' => 1,
                'is_locked' => 0,
            ]);

        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $message->getId(),
            'message_types_id' => $messageType->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $message = $message->fresh();
        $channel->addMessage($message);

        $region = Regions::getDefault($company, $app);
        if (! $region) {
            $region = Regions::create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'users_id' => 0,
                'name' => 'Region ' . uniqid(),
                'is_default' => 1,
                'is_deleted' => 0,
            ]);
        }

        $this->registerInternalIntegration($company->getId(), $region);

        $activity = $this->activityThatThrows();

        $result = $activity->execute($message, $app, []);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('401', $result['error']);
        // failWorkflow shape — proves the throw was caught in the closure, not by
        // executeIntegration (which would report() to Sentry and add a 'trace' key).
        $this->assertArrayHasKey('lead', $result);
        $this->assertEquals($lead->getId(), $result['lead']);
        $this->assertArrayNotHasKey('trace', $result);
    }

    private function registerInternalIntegration(int $companiesId, Regions $region): IntegrationsCompany
    {
        return IntegrationsCompany::firstOrCreate(
            [
                'companies_id' => $companiesId,
                'integrations_id' => Integrations::getByName(IntegrationsEnum::INTERNAL->value)->getId(),
                'region_id' => $region->getId(),
            ],
            [
                'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
                'is_active' => 1,
            ]
        );
    }

    private function activityThatThrows(): BaseAddLeadCommentFromAgentMessageActivity
    {
        return new class () extends BaseAddLeadCommentFromAgentMessageActivity {
            public function __construct()
            {
            }

            public function workflowId()
            {
                return null;
            }

            protected function getIntegration(): IntegrationsEnum
            {
                return IntegrationsEnum::INTERNAL;
            }

            protected function validateCompanyIntegration(Message $message): ?array
            {
                return null;
            }

            protected function addNoteToExternalSystem(Lead $lead, string $note, Message $message, Apps $app): mixed
            {
                throw new Exception('HTTP Error: 401');
            }
        };
    }
}
