<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Actions\EnsureProviderIntegrationAction;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Exceptions\WaSenderRefusedException;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * Link a company's WhatsApp number to an agent and return the QR to scan. The session posts to the
 * company's communication receiver, the number becomes a connection the agent owns, and that
 * ownership is all it takes for the agent to answer — no rule is made.
 *
 * WaSender generates the webhook_secret and returns it in the create-session response; it is kept
 * on the receiver, which is what the signature is checked against.
 */
class ConnectWhatsAppSessionAction
{
    protected SessionService $sessionService;

    public function __construct(
        protected readonly Apps $app,
        protected readonly Companies $company,
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
        // Asked again for the number it already linked, the agent gets the same session with a new
        // QR. A second session would take the company's key and the receiver's secret with it, and
        // leave the first one posting calls that are no longer signed.
        if ($this->linkedSessionIsForThisNumber()) {
            return new RefreshWhatsAppQrCodeAction(
                $this->app,
                $this->company,
                $this->agent,
                $this->sessionService
            )->execute();
        }

        // Linking another number replaces the one the agent had; an agent has one session.
        new DisconnectWhatsAppSessionAction(
            app: $this->app,
            company: $this->company,
            agent: $this->agent,
            remove: true,
            sessionService: $this->sessionService,
        )->execute();

        $receiver = new CommunicationReceivers()->ensure($this->app, $this->company, WaSenderHandler::slug());

        $name = $this->sessionName !== null && trim($this->sessionName) !== ''
            ? trim($this->sessionName)
            : $this->phoneNumber;

        $result = $this->sessionService->createAndConnectSession(
            name: $name,
            phoneNumber: $this->phoneNumber,
            webhookUrl: $receiver->getUrl(),
            webhookEvents: WebhookEventEnum::subscribable(),
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

    /**
     * False when the agent has no session, when WaSender no longer has it, or when it was made for
     * another number. A session WaSender describes without a number is taken to be this one: the
     * alternative is deleting a live session on a guess.
     */
    private function linkedSessionIsForThisNumber(): bool
    {
        $sessionId = (int) $this->agent->get(ConnectionFieldEnum::SESSION_ID->value);

        if ($sessionId <= 0) {
            return false;
        }

        try {
            $session = $this->sessionService->getSession($sessionId);
        } catch (WaSenderRefusedException) {
            return false;
        }

        $linked = Str::digitsOnly((string) ($session['phone_number'] ?? ''));

        return $linked === '' || $linked === Str::digitsOnly($this->phoneNumber);
    }

    /**
     * The agent owns the connection: session id / phone / receiver id are kept on it so update and
     * remove can read them back. The receiver keeps the secret the signature is checked against.
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

        // The row is what Communication sets the connection up from; saving it schedules that.
        new EnsureProviderIntegrationAction(
            $this->app,
            $this->company,
            $this->user,
            WaSenderHandler::class,
            array_filter([
                'phone_number' => Str::toE164($this->phoneNumber),
                'agent_id' => $this->agent->getId(),
                'session_id' => $sessionId !== null ? (int) $sessionId : null,
            ])
        )->execute();
    }
}
