<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Phone;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\Phone\Concerns\ReportsPhoneChannelStatus;
use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Throwable;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client as TwilioRestClient;

use function Sentry\captureException;

/**
 * Point an agent's Twilio voice number at the voice runtime, so nobody has to set it
 * in the Twilio console. A text number has no webhook to set: it is a Communication
 * connection the agent owns (ReportAgentTextConnectionAction says whether it is one).
 *
 * A webhook another system set is left alone and reported as `conflict` unless
 * the caller asks to overwrite it, because the number may already be in use. A
 * voice number left on another voice runtime (another environment, or an old
 * runtime URL) is not another system: it is moved to this one, with a warning.
 *
 * NON-BLOCKING but NOT SILENT: it is called on agent save and must never throw or
 * block the save, so it always returns a structured outcome instead of raising.
 * The outcome is logged AND persisted to the voice config as inbound_webhook
 * so the admin UI can show whether inbound is wired (and why not, when it isn't).
 */
class ConfigureAgentInboundWebhookAction
{
    use ReportsPhoneChannelStatus;

    private const AgentPhoneChannelEnum CHANNEL = AgentPhoneChannelEnum::VOICE;

    public function __construct(
        private readonly Agent $agent,
        private readonly bool $overwrite = false
    ) {
    }

    /**
     * @return array{status: string, message: string, url: string|null, warnings: list<string>}
     *   status: wired | conflict | idle | error
     */
    public function execute(): array
    {
        $result = $this->wire();
        $this->recordStatus($this->agent, self::CHANNEL, $result);

        return $result;
    }

    /**
     * @return array{status: string, message: string, url: string|null, warnings: list<string>}
     */
    private function wire(): array
    {
        $number = $this->agent->phoneNumber(self::CHANNEL);
        if ($number === null) {
            // Benign: nothing to wire. Clears any prior status if the number was removed.
            return $this->status('idle', 'No phone number set for this agent.');
        }

        $company = $this->agent->companies_id > 0
            ? Companies::find($this->agent->companies_id)
            : null;
        if ($company === null) {
            return $this->status('error', 'The agent has no company to read Twilio credentials from.');
        }

        try {
            $twilio = $this->twilio($company);

            // Twilio updates a number by SID, so resolve it first.
            $numbers = $twilio->incomingPhoneNumbers->read(['phoneNumber' => $number], 1);
            if ($numbers === []) {
                return $this->status('error', "{$number} is not a number in the connected Twilio account.");
            }

            $phone = $numbers[0];

            // After the number checks, so a typo or a bad credential never leaves a receiver behind.
            $webhook = $this->webhookUrl();
            if ($webhook === '') {
                return $this->status('error', 'The voice runtime URL is not configured.');
            }

            $current = trim((string) $phone->{self::CHANNEL->twilioUrlField()});
            $fromOtherRuntime = $current !== $webhook && VoiceRuntimeConfig::isRuntimeInboundUrl($current);
            if ($current !== '' && $current !== $webhook && ! $this->overwrite && ! $fromOtherRuntime) {
                return $this->status('conflict', "{$number} already points at another webhook, so it was left in place.", $current);
            }

            if ($current !== $webhook) {
                $twilio->incomingPhoneNumbers($phone->sid)->update([
                    self::CHANNEL->twilioUrlField() => $webhook,
                    self::CHANNEL->twilioMethodField() => 'POST',
                ]);
            }

            return $this->status(
                'wired',
                "Calls to {$number} reach this agent.",
                $webhook,
                $fromOtherRuntime
                    ? ["{$number} was moved here from another voice runtime ({$current}). A number can only reach one runtime, so if that environment saves it again, it moves back."]
                    : []
            );
        } catch (ValidationException $e) {
            // Expected, actionable misconfiguration — no need to page Sentry.
            return $this->status('error', $e->getMessage());
        } catch (RestException $e) {
            // Twilio answered, so its reason is the only thing that tells the admin what to fix.
            if ($e->getStatusCode() >= 500) {
                captureException($e);
            }

            return $this->status('error', "Twilio refused the inbound webhook: {$e->getMessage()}");
        } catch (Throwable $e) {
            captureException($e);

            return $this->status('error', 'Could not set the inbound webhook.');
        }
    }

    protected function twilio(Companies $company): TwilioRestClient
    {
        return TwilioClient::getInstanceByCompany($company);
    }

    private function webhookUrl(): string
    {
        return VoiceRuntimeConfig::inboundWebhookUrl();
    }
}
