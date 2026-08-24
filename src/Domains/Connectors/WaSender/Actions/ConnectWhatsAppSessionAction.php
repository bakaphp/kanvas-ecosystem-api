<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Connectors\WaSender\Webhooks\ProcessWaSenderWebhookJob;
use Kanvas\Connectors\WaSender\Workflows\AgentChannelResponderActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

/**
 * Link a company's WhatsApp number to a receptionist agent and return the QR to scan.
 *
 * Two non-obvious bits: the agent_id must live on the workflow rule (the webhook's fireWorkflow
 * never passes it), and WaSender generates the webhook_secret and returns it in the create-session
 * response — we store it so signature verification (a direct compare) accepts inbound calls.
 */
class ConnectWhatsAppSessionAction
{
    public const string RULE_NAME = 'WhatsApp Receptionist responder';

    protected SessionService $sessionService;
    private const string CHANNEL_SLUG_ATTRIBUTE = 'slug';
    private const string CHANNEL_SLUG_PATTERN = '/wa/';

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly UserInterface $user,
        protected readonly Agent $agent,
        protected readonly string $phoneNumber,
        protected readonly ?string $sessionName = null,
        ?SessionService $sessionService = null,
    ) {
        $this->sessionService = $sessionService ?? new SessionService($app, $company);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $receiver = $this->resolveReceiver();
        $this->bindAgentRule();

        $name = $this->sessionName !== null && trim($this->sessionName) !== ''
            ? trim($this->sessionName)
            : $this->phoneNumber;

        $result = $this->sessionService->createAndConnectSession(
            name: $name,
            phoneNumber: $this->phoneNumber,
            webhookUrl: $receiver->getUrl(),
            webhookEnabled: true,
            webhookEvents: $this->webhookEvents(),
        );

        $session = (array) ($result['session'] ?? []);
        $connection = (array) ($result['connection'] ?? []);

        $this->persistConnection($receiver, $session, $connection);

        $qrCode = SessionService::extractQr($connection) ?? SessionService::extractQr($session);

        // Some plans return the QR only from the dedicated endpoint, not the connect response.
        if ($qrCode === null && ($session['id'] ?? null) !== null) {
            $qrCode = SessionService::extractQr($this->sessionService->getSessionQrCode((int) $session['id']));
        }

        return [
            'session_id' => $session['id'] ?? null,
            'status' => (string) ($connection['status'] ?? $session['status'] ?? 'need_scan'),
            'qr_code' => $qrCode,
        ];
    }

    private function resolveReceiver(): ReceiverWebhook
    {
        $webhookAction = WorkflowAction::where('model_name', ProcessWaSenderWebhookJob::class)->firstOrFail();

        return ReceiverWebhook::firstOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'action_id' => $webhookAction->getId(),
            ],
            [
                'users_id' => $this->user->getId(),
                'name' => 'WhatsApp Receptionist',
                'description' => 'Inbound WhatsApp routed to the receptionist agent.',
                'configuration' => ['agent_id' => $this->agent->getId()],
                'is_active' => true,
                'run_async' => true,
            ]
        );
    }

    private function bindAgentRule(): void
    {
        $agentId = $this->agent->getId();
        $action = Action::where('model_name', AgentChannelResponderActivity::class)->firstOrFail();
        $systemModule = SystemModulesRepository::getByModelName(Channel::class, $this->app);
        $ruleType = RuleType::where('name', WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value)->firstOrFail();

        $rule = Rule::firstOrCreate(
            [
                'name' => self::RULE_NAME,
                'rules_types_id' => $ruleType->getId(),
                'systems_modules_id' => $systemModule->getId(),
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
            ],
            [
                'params' => ['agent_id' => $agentId],
                'pattern' => '1',
                'is_async' => 1,
                'is_deleted' => 0,
            ]
        );

        if (($rule->params['agent_id'] ?? null) !== $agentId) {
            $rule->params = array_merge($rule->params ?? [], ['agent_id' => $agentId]);
            $rule->saveOrFail();
        }

        // Scope to WhatsApp channels (prod: slug matches /wa/); otherwise this fires the WhatsApp
        // responder on every inbound channel message, and it reads chat_jid directly.
        $rule->getRulesConditions()->firstOrCreate(
            [
                'attribute_name' => self::CHANNEL_SLUG_ATTRIBUTE,
                'operator' => RuleConditionOperatorEnum::MATCHES->value,
                'value' => self::CHANNEL_SLUG_PATTERN,
            ],
            ['is_deleted' => 0],
        );

        $ruleWorkflowAction = RuleWorkflowAction::firstOrCreate(
            ['system_modules_id' => $systemModule->getId(), 'actions_id' => $action->getId()],
            ['is_deleted' => 0],
        );

        RuleAction::firstOrCreate(
            ['rules_id' => $rule->getId(), 'rules_workflow_actions_id' => $ruleWorkflowAction->getId()],
            ['weight' => 0, 'is_deleted' => 0],
        );
    }

    /**
     * The agent owns the connection: store session id / phone / receiver id on it so update+remove
     * can read them back. The receiver config keeps the secret (for verifySignature) + session id.
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $connection
     */
    private function persistConnection(
        ReceiverWebhook $receiver,
        array $session,
        array $connection
    ): void {
        $sessionId = $session['id'] ?? null;
        $secret = $session['webhook_secret'] ?? $connection['webhook_secret'] ?? null;
        // WaSender returns this session's own api_key on create — the key that authorizes its
        // /api/send-message (the account PAT can't send). Store it as the company's wasender_api_key
        // so the existing send path (MessageService reads company API_KEY first) uses it unchanged.
        $sessionApiKey = $session['api_key'] ?? $connection['api_key'] ?? null;

        $config = $receiver->configuration ?? [];
        $config['agent_id'] = $this->agent->getId();
        if ($secret !== null && $secret !== '') {
            $config['webhook_secret'] = (string) $secret;
        }
        if ($sessionId !== null) {
            $config['session_id'] = $sessionId;
        }
        $receiver->configuration = $config;
        $receiver->saveOrFail();

        if ($sessionApiKey !== null && $sessionApiKey !== '') {
            $this->company->set(ConfigurationEnum::API_KEY->value, (string) $sessionApiKey);
        }

        if ($sessionId !== null) {
            $this->agent->set(ConnectionFieldEnum::SESSION_ID->value, $sessionId);
        }
        $this->agent->set(ConnectionFieldEnum::PHONE_NUMBER->value, $this->phoneNumber);
        $this->agent->set(ConnectionFieldEnum::RECEIVER_ID->value, $receiver->getId());
    }

    /**
     * @return list<string>
     */
    private function webhookEvents(): array
    {
        return WebhookEventEnum::subscribable();
    }
}
