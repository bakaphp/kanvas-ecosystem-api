<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Phone;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Throwable;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client as TwilioRestClient;

use function Sentry\captureException;

/**
 * Release a Twilio number's inbound webhook when an agent stops using it.
 *
 * Voice clears the webhook only when it is ours, so a released number stops
 * reaching the runtime while a webhook someone else set survives.
 *
 * SMS stays in place unless forced: the webhook points at the company's receiver,
 * and clearing it could stop the company's inbound texts on its main number.
 *
 * Neither happens while another agent holds the number on that channel, forced or
 * not, because clearing it would kill that agent's inbound. The check is across
 * every app: a phone number belongs to exactly one Twilio account.
 *
 * NON-BLOCKING and NEVER throws: it runs on agent save and must not break it.
 * Returns a structured outcome the caller can log.
 */
class ReleaseInboundWebhookForNumberAction
{
    public function __construct(
        private readonly string $number,
        private readonly Companies $company,
        private readonly AgentPhoneChannelEnum $channel = AgentPhoneChannelEnum::VOICE,
        // The agent that just moved off the number. It no longer carries it (the
        // update already applied), so this is belt-and-suspenders against a race
        // where the caller passes an agent still holding the old value.
        private readonly ?int $exceptAgentId = null,
        private readonly bool $force = false,
    ) {
    }

    /**
     * @return array{status: string, message: string}
     *   status: released | kept | idle | error
     */
    public function execute(): array
    {
        $number = trim($this->number);
        if ($number === '') {
            return $this->result('idle', 'No previous number to release.');
        }

        if ($this->stillOwned($number)) {
            return $this->result('kept', "{$number} is still assigned to another agent; webhook left in place.");
        }

        if ($this->channel === AgentPhoneChannelEnum::SMS && ! $this->force) {
            return $this->result('kept', "{$number} keeps its SMS webhook; pass force to clear it.");
        }

        try {
            $twilio = $this->twilio();

            $numbers = $twilio->incomingPhoneNumbers->read(['phoneNumber' => $number], 1);
            if ($numbers === []) {
                // Not on this account (maybe never wired, or a different account) —
                // nothing to clear.
                return $this->result('idle', "{$number} is not a number in the connected Twilio account.");
            }

            $current = trim((string) $numbers[0]->{$this->channel->twilioUrlField()});
            if (! $this->force && $current !== VoiceRuntimeConfig::inboundWebhookUrl()) {
                return $this->result('kept', "{$number} does not point at our webhook; left in place.");
            }

            // Re-wired automatically if any agent claims it again.
            $twilio->incomingPhoneNumbers($numbers[0]->sid)->update([
                $this->channel->twilioUrlField() => '',
                $this->channel->twilioMethodField() => 'POST',
            ]);

            return $this->result('released', "Cleared the inbound webhook on {$number}.");
        } catch (ValidationException $e) {
            // Expected, actionable misconfiguration — no need to page Sentry.
            return $this->result('error', $e->getMessage());
        } catch (RestException $e) {
            // Twilio answered, so its reason is the only thing that tells the admin what to fix.
            if ($e->getStatusCode() >= 500) {
                captureException($e);
            }

            return $this->result('error', "Twilio refused to clear the inbound webhook: {$e->getMessage()}");
        } catch (Throwable $e) {
            captureException($e);

            return $this->result('error', 'Could not reach Twilio to clear the inbound webhook.');
        }
    }

    protected function twilio(): TwilioRestClient
    {
        return TwilioClient::getInstanceByCompany($this->company);
    }

    private function stillOwned(string $number): bool
    {
        if (AgentsRepository::normalizePhoneNumber($number) === '') {
            // Un-normalizable input — never risk clearing a webhook on a guess.
            return true;
        }

        return AgentsRepository::getAgentHoldingPhoneNumber($number, $this->channel, $this->exceptAgentId) !== null;
    }

    /**
     * @return array{status: string, message: string}
     */
    private function result(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }
}
