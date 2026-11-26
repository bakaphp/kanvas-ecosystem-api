<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Communication\Connections\Actions\RetireConnectionAction;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Throwable;

/**
 * Disconnect an agent's WhatsApp connection. The agent owns it, so the session id is read off the
 * agent (set by ConnectWhatsAppSessionAction).
 *
 * - pause (remove = false): unlink WhatsApp; the session + QR-reconnect stay.
 * - remove (remove = true): delete the WaSender session, retire the number's connection and clear
 *   the agent's connection fields, so nothing is sent from or answered on it after removal.
 */
class DisconnectWhatsAppSessionAction
{
    protected SessionService $sessionService;

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly Agent $agent,
        protected readonly bool $remove = false,
        ?SessionService $sessionService = null,
    ) {
        $this->sessionService = $sessionService ?? new SessionService($app, $company);
    }

    public function execute(): bool
    {
        $sessionId = (int) $this->agent->get(ConnectionFieldEnum::SESSION_ID->value);
        if ($sessionId <= 0) {
            return false;
        }

        if (! $this->remove) {
            $this->sessionService->disconnectSession($sessionId);

            return true;
        }

        try {
            $this->sessionService->deleteSession($sessionId);
        } catch (Throwable $e) {
            report($e);
        }

        // Before the connection is retired: an agent that still declares the number keeps it listed,
        // and a listed account that was retired is never provisioned again.
        $this->clearAgentConnection();
        $this->retireConnection($sessionId);

        return true;
    }

    /**
     * The number is gone, so the connection is: retired, and no longer selected on the integration
     * or the receiver, or the next setup pass would bring it back. The receiver itself stays (it is
     * the company's, and a new session links to the same one), and so does its secret: without one
     * it would take unsigned calls.
     */
    private function retireConnection(int $sessionId): void
    {
        $connection = WaSenderHandler::connectionFor($this->app, $this->company);

        // Another agent's session is the live one: removing this agent's must not take it down.
        if ($connection === null || ! $this->serves($connection, $sessionId)) {
            return;
        }

        $receiver = WaSenderHandler::receiverFor($connection->app, $connection->company);

        if ($receiver !== null) {
            $receiver->configuration = array_diff_key($receiver->configuration ?? [], array_flip(['agent_id', 'session_id']));
            $receiver->saveOrFail();
        }

        $integrationCompany = IntegrationsCompany::query()->find($connection->integrations_company_id);

        if ($integrationCompany !== null) {
            $integrationCompany->config = array_diff_key(
                $integrationCompany->config ?? [],
                array_flip(['phone_number', 'agent_id', 'session_id'])
            ) ?: null;
            $integrationCompany->saveOrFail();
        }

        new RetireConnectionAction($connection, 'session_removed')->execute();
    }

    private function serves(Connection $connection, int $sessionId): bool
    {
        $connectionSession = (int) ($connection->config['session_id'] ?? 0);

        return $connectionSession === 0
            ? $connection->agent()?->is($this->agent) !== false
            : $connectionSession === $sessionId;
    }

    private function clearAgentConnection(): void
    {
        $this->agent->del(ConnectionFieldEnum::SESSION_ID->value);
        $this->agent->del(ConnectionFieldEnum::PHONE_NUMBER->value);
        $this->agent->del(ConnectionFieldEnum::RECEIVER_ID->value);
    }
}
