<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Phone;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Connectors\Twilio\Services\TwilioReceiverService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;

use function Sentry\captureException;

use Throwable;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client as TwilioRestClient;

/**
 * Point an agent's Twilio number at the right inbound webhook, so nobody has to
 * set it in the Twilio console: the voice runtime for calls, the company's Twilio
 * receiver for texts.
 *
 * A webhook another system set is left alone and reported as `conflict` unless
 * the caller asks to overwrite it, because the number may already be in use.
 *
 * NON-BLOCKING but NOT SILENT: it is called on agent save and must never throw or
 * block the save, so it always returns a structured outcome instead of raising.
 * The outcome is logged AND persisted to the channel's config as inbound_webhook
 * so the admin UI can show whether inbound is wired (and why not, when it isn't).
 */
class ConfigureAgentInboundWebhookAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly AgentPhoneChannelEnum $channel = AgentPhoneChannelEnum::VOICE,
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
        $this->persistStatus($result);
        $this->log($result);

        return $result;
    }

    /**
     * @return array{status: string, message: string, url: string|null, warnings: list<string>}
     */
    private function wire(): array
    {
        $config = $this->agent->{$this->channel->configColumn()} ?? [];
        $number = trim((string) ($config['phone_number'] ?? ''));
        if ($number === '') {
            // Benign: nothing to wire. Clears any prior status if the number was removed.
            return $this->result('idle', 'No phone number set for this agent.');
        }

        $company = $this->agent->companies_id > 0
            ? Companies::find($this->agent->companies_id)
            : null;
        if ($company === null) {
            return $this->result('error', 'The agent has no company to read Twilio credentials from.');
        }

        try {
            $twilio = $this->twilio($company);

            // Twilio updates a number by SID, so resolve it first.
            $numbers = $twilio->incomingPhoneNumbers->read(['phoneNumber' => $number], 1);
            if ($numbers === []) {
                return $this->result('error', "{$number} is not a number in the connected Twilio account.");
            }

            $phone = $numbers[0];
            if ($this->channel === AgentPhoneChannelEnum::SMS && ! $phone->capabilities?->getSms()) {
                return $this->result('error', "{$number} cannot send or receive SMS.");
            }

            // After the number checks, so a typo or a bad credential never leaves a receiver behind.
            $webhook = $this->webhookUrl();
            if ($webhook === '') {
                return $this->result('error', 'The voice runtime URL is not configured.');
            }

            $current = trim((string) $phone->{$this->channel->twilioUrlField()});
            if ($current !== '' && $current !== $webhook && ! $this->overwrite) {
                return $this->result('conflict', "{$number} already points at another webhook, so it was left in place.", $current);
            }

            if ($current !== $webhook) {
                $twilio->incomingPhoneNumbers($phone->sid)->update([
                    $this->channel->twilioUrlField() => $webhook,
                    $this->channel->twilioMethodField() => 'POST',
                ]);
            }

            return $this->result(
                'wired',
                $this->channel === AgentPhoneChannelEnum::VOICE
                    ? "Calls to {$number} reach this agent."
                    : "Texts to {$number} reach this company's Twilio receiver.",
                $webhook,
                $this->channel === AgentPhoneChannelEnum::SMS
                    ? $this->smsWarnings(
                        $twilio,
                        $company,
                        $number,
                        $phone->sid
                    )
                    : []
            );
        } catch (ValidationException $e) {
            // Expected, actionable misconfiguration — no need to page Sentry.
            return $this->result('error', $e->getMessage());
        } catch (RestException $e) {
            // Twilio answered, so its reason is the only thing that tells the admin what to fix.
            if ($e->getStatusCode() >= 500) {
                captureException($e);
            }

            return $this->result('error', "Twilio refused the inbound webhook: {$e->getMessage()}");
        } catch (Throwable $e) {
            captureException($e);

            return $this->result('error', 'Could not set the inbound webhook.');
        }
    }

    protected function twilio(Companies $company): TwilioRestClient
    {
        return TwilioClient::getInstanceByCompany($company);
    }

    private function webhookUrl(): string
    {
        if ($this->channel === AgentPhoneChannelEnum::VOICE) {
            return VoiceRuntimeConfig::inboundWebhookUrl($this->agent->app);
        }

        return new TwilioReceiverService()->ensure($this->agent)->getUrl();
    }

    /**
     * @return list<string>
     */
    private function smsWarnings(
        TwilioRestClient $twilio,
        Companies $company,
        string $number,
        string $numberSid
    ): array {
        $warnings = [];

        $allowed = TwilioConfigurationEnum::allowedFromNumbers($company);
        if ($allowed !== [] && ! in_array(Str::toE164($number), $allowed, true)) {
            $warnings[] = "{$number} is not in the company's allowed From numbers, so texts from it will be refused.";
        }

        if ($this->messagingServiceHandlesInbound($twilio, $company, $numberSid)) {
            $warnings[] = "{$number} is in a Messaging Service that handles inbound itself, so its webhook on the number is ignored.";
        }

        return $warnings;
    }

    /**
     * Only feeds a warning, so a failed lookup must not turn a wired number into an error.
     */
    private function messagingServiceHandlesInbound(TwilioRestClient $twilio, Companies $company, string $numberSid): bool
    {
        $serviceSid = Str::trimToNull((string) $company->get(TwilioConfigurationEnum::TWILIO_MESSAGING_SERVICE_SID->value));
        if ($serviceSid === null) {
            return false;
        }

        try {
            $service = $twilio->messaging->v1->services($serviceSid);
            if ($service->fetch()->useInboundWebhookOnNumber) {
                return false;
            }

            // Twilio answers 404 when the number is not in this service.
            $service->phoneNumbers($numberSid)->fetch();

            return true;
        } catch (RestException) {
            return false;
        }
    }

    /**
     * @param array{status: string, message: string, url: string|null, warnings: list<string>} $result
     */
    private function persistStatus(array $result): void
    {
        $column = $this->channel->configColumn();
        $config = $this->agent->{$column} ?? [];

        // An agent that never had this channel keeps a null column, which is what the number lookups narrow on.
        if ($config === [] && $result['status'] === 'idle') {
            return;
        }

        $config['inbound_webhook'] = $result + ['at' => now()->toIso8601String()];

        // Direct, quiet write — this is system metadata, not a user edit, and
        // must not re-trigger observers or the save mutation.
        $this->agent->{$column} = $config;
        $this->agent->saveQuietly();
    }

    /**
     * @param array{status: string, message: string, url: string|null, warnings: list<string>} $result
     */
    private function log(array $result): void
    {
        $line = "{$this->channel->value} inbound webhook [{$result['status']}] agent {$this->agent->uuid}: {$result['message']}";
        if (in_array($result['status'], ['error', 'conflict'], true)) {
            logger()->warning($line);
        } else {
            logger()->info($line);
        }
    }

    /**
     * @param list<string> $warnings
     *
     * @return array{status: string, message: string, url: string|null, warnings: list<string>}
     */
    private function result(
        string $status,
        string $message,
        ?string $url = null,
        array $warnings = []
    ): array {
        return [
            'status' => $status,
            'message' => $message,
            'url' => $url,
            'warnings' => $warnings,
        ];
    }
}
