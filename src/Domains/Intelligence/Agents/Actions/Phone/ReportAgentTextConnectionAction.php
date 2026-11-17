<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Phone;

use Kanvas\Communication\Communication;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Intelligence\Agents\Actions\Phone\Concerns\ReportsPhoneChannelStatus;
use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Whether texts to an agent's number reach it. A text number has no webhook of its own: it is a
 * Communication connection, so the answer is that connection's (it exists, it can carry a message,
 * this agent owns it). Run it after the company's connections were reconciled
 * (RefreshAgentConnectionsAction), or it reports the state from before the number was set.
 */
final class ReportAgentTextConnectionAction
{
    use ReportsPhoneChannelStatus;

    public function __construct(
        private readonly Agent $agent
    ) {
    }

    /**
     * @return array{status: string, message: string, url: string|null, warnings: list<string>}
     *   status: wired | pending | idle | error
     */
    public function execute(): array
    {
        $result = $this->connectionStatus();
        $this->recordStatus($this->agent, AgentPhoneChannelEnum::SMS, $result);

        return $result;
    }

    /**
     * @return array{status: string, message: string, url: string|null, warnings: list<string>}
     */
    private function connectionStatus(): array
    {
        $number = $this->agent->smsPhoneNumber();
        if ($number === null) {
            return $this->status('idle', 'No phone number set for this agent.');
        }

        $company = $this->agent->companies_id > 0 ? $this->agent->company : null;
        if ($company === null) {
            return $this->status('error', 'The agent has no company to receive texts for.');
        }

        $connection = Communication::for($this->agent->app, $company)
            ->connectionFor(ChannelTypeEnum::SMS, fromAccount: $number);

        if ($connection === null) {
            return $this->status(
                'error',
                'Connect the Twilio integration for this company to receive texts on ' . $number . '.'
            );
        }

        if ($connection->agent()?->getId() !== $this->agent->getId()) {
            return $this->status('error', "{$number} is connected, but another agent answers it.");
        }

        $reason = $connection->unavailableReason();
        if ($reason === null) {
            return $this->status('wired', "Texts to {$number} are answered by this agent.");
        }

        $settingUp = in_array(
            $connection->setup_status,
            [SetupStatusEnum::PENDING_SETUP, SetupStatusEnum::PROVISIONING],
            true
        );

        return $settingUp
            ? $this->status('pending', "{$number} is being set up. This agent answers its texts once it is ready.")
            : $this->status('error', "{$number} cannot receive texts: " . ($connection->last_error ?? $reason) . '.');
    }
}
