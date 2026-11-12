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
use Kanvas\Communication\Workflows\HumanAgentChannelResponseActivity;
use Kanvas\Workflow\Services\WorkflowActionDiscoveryService;
use Tests\TestCase;

/**
 * Twilio and Mailgun moved into Communication; names written before the move — in rows and in
 * queued payloads — must keep reaching the same code.
 */
final class LegacyConnectorNamesTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow'];

    private const array MOVED = [
        'Kanvas\\Connectors\\Twilio\\Webhooks\\ProcessTwilioWebhookJob' => ProcessTwilioWebhookJob::class,
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

    public function testTheOldNamesAreNotCataloguedAsSecondActions(): void
    {
        $discovery = new WorkflowActionDiscoveryService();
        $catalogued = array_column($discovery->discover(), 'class');
        $untagged = $discovery->findUntagged();

        foreach (array_keys(self::MOVED) as $legacy) {
            $this->assertNotContains($legacy, $untagged);
            $this->assertNotContains($legacy, $catalogued);
        }

        $this->assertContains(ProcessTwilioWebhookJob::class, $catalogued);
        $this->assertContains(AgentInboxWebhookJob::class, $catalogued);
        $this->assertContains(HumanAgentChannelResponseActivity::class, $catalogued);
    }

    public function testTheMigrationRepointsSavedActionsAndHandlers(): void
    {
        // Rows the environment already holds, under either name, would claim the target first.
        foreach (['actions' => 'model_name', 'integrations' => 'handler'] as $table => $column) {
            DB::connection('workflow')
                ->table($table)
                ->where(
                    fn ($query) => $query->where($column, 'like', '%\\\\Twilio\\\\%')
                        ->orWhere($column, 'like', '%\\\\Mailgun\\\\%')
                        ->orWhere($column, 'like', '%\\\\HumanAgentChannelResponseActivity')
                )
                ->update([$column => DB::raw("CONCAT('parked:', {$column})")]);
        }

        $actions = [
            $this->legacyAction('Kanvas\\Connectors\\Twilio\\Webhooks\\ProcessTwilioWebhookJob') => ProcessTwilioWebhookJob::class,
            $this->legacyAction('Kanvas\\Connectors\\Mailgun\\Webhooks\\AgentInboxWebhookJob') => AgentInboxWebhookJob::class,
            $this->legacyAction('Kanvas\\Connectors\\Twilio\\Workflows\\HumanAgentChannelResponseActivity') => HumanAgentChannelResponseActivity::class,
        ];
        $integrations = [
            $this->legacyIntegration('Kanvas\\Connectors\\Twilio\\Handlers\\TwilioHandlers') => TwilioHandlers::class,
            $this->legacyIntegration('Kanvas\\Connectors\\Mailgun\\Handlers\\MailgunHandler') => MailgunHandler::class,
        ];

        $migration = require base_path('database/migrations/Workflow/2026_11_10_100000_repoint_moved_connectors_to_communication.php');
        $migration->up();

        foreach ($actions as $id => $current) {
            $this->assertSame($current, DB::connection('workflow')->table('actions')->where('id', $id)->value('model_name'));
        }

        foreach ($integrations as $id => $current) {
            $this->assertSame($current, DB::connection('workflow')->table('integrations')->where('id', $id)->value('handler'));
        }
    }

    private function legacyAction(string $class): int
    {
        return DB::connection('workflow')->table('actions')->insertGetId([
            'name' => class_basename($class) . ' (legacy)',
            'model_name' => $class,
            'is_deleted' => 0,
            'created_at' => now(),
        ]);
    }

    private function legacyIntegration(string $handler): int
    {
        return DB::connection('workflow')->table('integrations')->insertGetId([
            'uuid' => (string) fake()->uuid(),
            'name' => uniqid('legacy_'),
            'handler' => $handler,
            'apps_id' => 0,
            'is_deleted' => 0,
            'created_at' => now(),
        ]);
    }
}
