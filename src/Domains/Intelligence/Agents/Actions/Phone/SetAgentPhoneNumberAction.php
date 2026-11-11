<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Phone;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;

/**
 * Give an agent a number on one channel, or take it away with a null number.
 * Sending the same number again re-runs the wiring, which is the admin retry.
 */
class SetAgentPhoneNumberAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly AgentPhoneChannelEnum $channel,
        private readonly ?string $number,
        private readonly bool $overwrite = false,
        private readonly bool $clearPreviousWebhook = false,
    ) {
    }

    /**
     * @return array{phone_number: string|null, status: string, message: string, url: string|null, warnings: list<string>}
     */
    public function execute(): array
    {
        $number = $this->cleanNumber();
        if ($number !== null) {
            self::assertNumberAvailable($number, $this->agent);
        }

        $column = $this->channel->configColumn();
        $config = $this->agent->{$column} ?? [];
        $previous = $this->agent->phoneNumber($this->channel);

        unset($config['inbound_webhook']);
        if ($number === null) {
            unset($config['phone_number']);
        } else {
            $config['phone_number'] = $number;
        }

        $this->agent->{$column} = $config === [] ? null : $config;
        $this->agent->save();

        $result = new ConfigureAgentInboundWebhookAction($this->agent, $this->channel, $this->overwrite)->execute();

        if (
            $previous !== null
            && AgentsRepository::normalizePhoneNumber($previous) !== AgentsRepository::normalizePhoneNumber((string) $number)
        ) {
            new ReleaseInboundWebhookForNumberAction(
                number: $previous,
                company: $this->agent->company,
                channel: $this->channel,
                exceptAgentId: $this->agent->getId(),
                force: $this->clearPreviousWebhook,
            )->execute();
        }

        return ['phone_number' => $number] + $result;
    }

    /**
     * Only a number new to the agent is checked, so duplicates that predate this rule do not block
     * unrelated saves. The same agent may use one number for voice and SMS.
     */
    public static function assertNumberAvailable(string $number, ?Agent $agent = null): void
    {
        $current = [
            AgentsRepository::normalizePhoneNumber((string) $agent?->phoneNumber(AgentPhoneChannelEnum::VOICE)),
            AgentsRepository::normalizePhoneNumber((string) $agent?->phoneNumber(AgentPhoneChannelEnum::SMS)),
        ];

        if (in_array(AgentsRepository::normalizePhoneNumber($number), $current, true)) {
            return;
        }

        if (AgentsRepository::getAgentHoldingPhoneNumber(number: $number, exceptAgentId: $agent?->getId()) !== null) {
            throw new ValidationException("{$number} is already assigned to another agent.");
        }
    }

    /**
     * A typo must fail here: read as empty, it would remove the agent's current number.
     */
    private function cleanNumber(): ?string
    {
        if ($this->number === null) {
            return null;
        }

        $number = AgentsRepository::cleanPhoneNumber($this->number);
        if ($number === '') {
            throw new ValidationException("{$this->number} is not a valid phone number.");
        }

        return $number;
    }
}
