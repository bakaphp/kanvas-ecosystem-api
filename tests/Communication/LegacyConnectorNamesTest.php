<?php

declare(strict_types=1);

namespace Tests\Communication;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\AgentChannelResponderAction as MailgunResponderAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Handlers\MailgunHandler;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Jobs\ProvisionAgentMailboxJob;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\AgentInboxWebhookJob;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\AgentProcessEmailWebhookJob;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\EmailDeliveryWebhookJob;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\EmailOpenedWebhookJob;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\ScribePdfInboundWebhookJob;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Workflows\AgentChannelResponderActivity as MailgunResponderActivity;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Actions\AgentChannelResponderAction as TwilioResponderAction;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Actions\FlushSmsBurstAction;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Handlers\TwilioHandlers;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Webhooks\ProcessTwilioMessageStatusWebhookJob;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Webhooks\ProcessTwilioWebhookJob;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Workflows\AgentChannelResponderActivity as TwilioResponderActivity;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Communication\Workflows\HumanAgentChannelResponseActivity;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Services\WorkflowActionCatalog;
use Kanvas\Workflow\Services\WorkflowActionDiscoveryService;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

/**
 * Twilio and Mailgun moved into Communication; names written before the move — in rows and in
 * queued payloads — must keep reaching the same code, and the deploy-time sync must keep every
 * receiver and rule on the row it already points at.
 */
final class LegacyConnectorNamesTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow'];

    private const string LEGACY_TWILIO_WEBHOOK = 'Kanvas\\Connectors\\Twilio\\Webhooks\\ProcessTwilioWebhookJob';

    private const array MOVED = [
        self::LEGACY_TWILIO_WEBHOOK => ProcessTwilioWebhookJob::class,
        'Kanvas\\Connectors\\Twilio\\Webhooks\\ProcessTwilioMessageStatusWebhookJob' => ProcessTwilioMessageStatusWebhookJob::class,
        'Kanvas\\Connectors\\Twilio\\Workflows\\AgentChannelResponderActivity' => TwilioResponderActivity::class,
        'Kanvas\\Connectors\\Twilio\\Workflows\\HumanAgentChannelResponseActivity' => HumanAgentChannelResponseActivity::class,
        'Kanvas\\Connectors\\Twilio\\Handlers\\TwilioHandlers' => TwilioHandlers::class,
        // Queued by class name: the support-mode delayed turn and the burst flush.
        'Kanvas\\Connectors\\Twilio\\Actions\\AgentChannelResponderAction' => TwilioResponderAction::class,
        'Kanvas\\Connectors\\Twilio\\Actions\\FlushSmsBurstAction' => FlushSmsBurstAction::class,
        'Kanvas\\Connectors\\Mailgun\\Actions\\AgentChannelResponderAction' => MailgunResponderAction::class,
        'Kanvas\\Connectors\\Mailgun\\Webhooks\\AgentInboxWebhookJob' => AgentInboxWebhookJob::class,
        'Kanvas\\Connectors\\Mailgun\\Webhooks\\AgentProcessEmailWebhookJob' => AgentProcessEmailWebhookJob::class,
        'Kanvas\\Connectors\\Mailgun\\Webhooks\\ScribePdfInboundWebhookJob' => ScribePdfInboundWebhookJob::class,
        'Kanvas\\Connectors\\Mailgun\\Webhooks\\EmailDeliveryWebhookJob' => EmailDeliveryWebhookJob::class,
        'Kanvas\\Connectors\\Mailgun\\Webhooks\\EmailOpenedWebhookJob' => EmailOpenedWebhookJob::class,
        'Kanvas\\Connectors\\Mailgun\\Workflows\\AgentChannelResponderActivity' => MailgunResponderActivity::class,
        'Kanvas\\Connectors\\Mailgun\\Handlers\\MailgunHandler' => MailgunHandler::class,
        'Kanvas\\Connectors\\Mailgun\\Jobs\\ProvisionAgentMailboxJob' => ProvisionAgentMailboxJob::class,
    ];

    public function testEveryPersistedOldNameIsTheMovedClass(): void
    {
        foreach (self::MOVED as $legacy => $current) {
            $this->assertTrue(is_subclass_of($legacy, $current), "{$legacy} must extend {$current}");
        }
    }

    public function testTheOldNamesAreCarriedByTheMovedClassNotCataloguedAsSecondActions(): void
    {
        $discovery = new WorkflowActionDiscoveryService();
        $entries = $discovery->discover();
        $catalogued = array_column($entries, 'class');
        $legacyNames = array_column($entries, 'legacy_names', 'class');
        $untagged = $discovery->findUntagged();

        foreach (self::MOVED as $legacy => $current) {
            $this->assertNotContains($legacy, $untagged);
            $this->assertNotContains($legacy, $catalogued);

            if (array_key_exists($current, $legacyNames)) {
                $this->assertContains($legacy, $legacyNames[$current], "{$current} must carry {$legacy} as a legacy name");
            }
        }

        $this->assertContains(ProcessTwilioWebhookJob::class, $catalogued);
        $this->assertContains(AgentInboxWebhookJob::class, $catalogued);
        $this->assertContains(HumanAgentChannelResponseActivity::class, $catalogued);
    }

    /**
     * On 1.x the deploy syncs the catalog and migrations wait for a hand: the row has to be renamed
     * by the sync itself, or every `forJob()` lookup misses until someone runs them.
     */
    public function testTheSyncKeepsAMovedClassOnTheRowItsReceiversPointAt(): void
    {
        $this->parkExistingRows();
        $legacyId = $this->action(self::LEGACY_TWILIO_WEBHOOK);
        $receiver = $this->receiverOn($legacyId);

        new WorkflowActionCatalog()->register(ProcessTwilioWebhookJob::class);

        $this->assertSame(ProcessTwilioWebhookJob::class, $this->modelNameOf($legacyId));
        $this->assertSame($legacyId, (int) $receiver->fresh()->action_id);
        $this->assertTrue(ReceiverWebhook::query()->whereKey($receiver->getId())->forJob(ProcessTwilioWebhookJob::class)->exists());
    }

    public function testTheSyncAbsorbsAnOldRowAnEarlierSyncLeftBesideTheNewOne(): void
    {
        $this->parkExistingRows();
        $currentId = $this->action(ProcessTwilioWebhookJob::class);
        $legacyId = $this->action(self::LEGACY_TWILIO_WEBHOOK);
        $receiver = $this->receiverOn($legacyId);

        new WorkflowActionCatalog()->register(ProcessTwilioWebhookJob::class);

        $this->assertSame($currentId, (int) $receiver->fresh()->action_id);
        $this->assertNull($this->modelNameOf($legacyId));
    }

    public function testAnIntegrationSavedUnderTheOldHandlerNameStillFindsItsProvider(): void
    {
        $row = new IntegrationsCompany();
        $row->setRelation('integration', new Integrations(['handler' => 'Kanvas\\Connectors\\Twilio\\Handlers\\TwilioHandlers']));

        $this->assertSame(TwilioHandlers::class, new ProviderRegistry()->forIntegration($row));
    }

    /**
     * Rows the environment already holds, under either name, would claim the class first.
     */
    private function parkExistingRows(): void
    {
        DB::connection('workflow')
            ->table('actions')
            ->where('model_name', 'like', '%ProcessTwilioWebhookJob')
            ->update(['model_name' => DB::raw("CONCAT('parked:', model_name)")]);
    }

    private function action(string $class): int
    {
        return DB::connection('workflow')->table('actions')->insertGetId([
            'name' => class_basename($class),
            'model_name' => $class,
            'is_deleted' => 0,
            'created_at' => now(),
        ]);
    }

    private function receiverOn(int $actionId): ReceiverWebhook
    {
        return ReceiverWebhook::factory()
            ->app($this->currentApp()->getId())
            ->user(auth()->user()->getId())
            ->company($this->currentCompany()->getId())
            ->create(['action_id' => $actionId, 'configuration' => []]);
    }

    private function modelNameOf(int $actionId): ?string
    {
        return DB::connection('workflow')->table('actions')->where('id', $actionId)->value('model_name');
    }
}
