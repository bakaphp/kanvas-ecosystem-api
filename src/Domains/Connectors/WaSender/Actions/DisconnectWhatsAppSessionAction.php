<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Rule;
use Throwable;

/**
 * Disconnect an agent's WhatsApp connection. The agent owns it, so the session id / receiver id are
 * read off the agent (set by ConnectWhatsAppSessionAction).
 *
 * - pause (remove = false): unlink WhatsApp; the session + QR-reconnect stay.
 * - remove (remove = true): delete the WaSender session AND tear down our routing (receiver + rule)
 *   and clear the agent's connection fields, so nothing keeps firing after removal.
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

        $this->deactivateReceiver();
        $this->deactivateRule();
        $this->clearAgentConnection();

        return true;
    }

    private function deactivateReceiver(): void
    {
        $receiverId = (int) $this->agent->get(ConnectionFieldEnum::RECEIVER_ID->value);
        if ($receiverId <= 0) {
            return;
        }

        $receiver = ReceiverWebhook::find($receiverId);
        if ($receiver !== null) {
            $receiver->is_active = false;
            $receiver->saveOrFail();
        }
    }

    private function deactivateRule(): void
    {
        $rule = Rule::query()
            ->where('name', ConnectWhatsAppSessionAction::RULE_NAME)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($rule === null) {
            return;
        }

        $rule->getRulesConditions()->update(['is_deleted' => 1]);
        $rule->workflowActivities()->update(['is_deleted' => 1]);
        $rule->softDelete();
    }

    private function clearAgentConnection(): void
    {
        $this->agent->del(ConnectionFieldEnum::SESSION_ID->value);
        $this->agent->del(ConnectionFieldEnum::PHONE_NUMBER->value);
        $this->agent->del(ConnectionFieldEnum::RECEIVER_ID->value);
    }
}
