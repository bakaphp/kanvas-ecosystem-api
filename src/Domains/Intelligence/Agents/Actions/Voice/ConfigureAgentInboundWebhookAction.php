<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Throwable;

use function Sentry\captureException;

/**
 * Point an agent's Twilio number at the voice runtime's inbound webhook, so a
 * call to that number reaches this agent — no manual Twilio-console step.
 *
 * Sets the number's `voiceUrl` to `{VOICE_RUNTIME_URL}/twilio/incoming` via the
 * Twilio REST API (company creds through the existing Twilio connector Client).
 *
 * NON-BLOCKING but NOT SILENT: it is called on agent save and must never throw or
 * block the save, so it always returns a structured outcome instead of raising.
 * The outcome is logged AND persisted to voice_config.inbound_webhook so the
 * admin Voice tab can show whether inbound is wired (and why not, when it isn't).
 * A missing number is a benign "idle"; a set number that fails to wire (missing
 * Twilio creds, number not in the account, API error) is a visible "error".
 */
class ConfigureAgentInboundWebhookAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly AppInterface $app,
    ) {
    }

    /**
     * @return array{status: string, message: string, url: string|null}
     *   status: wired | idle | error
     */
    public function execute(): array
    {
        $result = $this->wire();
        $this->persistStatus($result);
        $this->log($result);

        return $result;
    }

    /**
     * @return array{status: string, message: string, url: string|null}
     */
    private function wire(): array
    {
        $voice = $this->agent->voice_config ?? [];
        $number = trim((string) ($voice['phone_number'] ?? ''));
        if ($number === '') {
            // Benign: nothing to wire. Clears any prior status if the number was removed.
            return $this->result('idle', 'No phone number set for this agent.');
        }

        $runtimeUrl = VoiceRuntimeConfig::url($this->app);
        if ($runtimeUrl === '') {
            return $this->result('error', 'The voice runtime URL is not configured.');
        }

        $company = $this->agent->companies_id > 0
            ? Companies::find($this->agent->companies_id)
            : null;
        if ($company === null) {
            return $this->result('error', 'The agent has no company to read Twilio credentials from.');
        }

        try {
            // Company creds if the company has its own; else the app-level creds
            // (shared account). Throws ValidationException when neither is set.
            $twilio = TwilioClient::getInstanceByCompanyOrApp($company, $this->agent->app);

            // Twilio updates a number by SID, so resolve it first.
            $numbers = $twilio->incomingPhoneNumbers->read(['phoneNumber' => $number], 1);
            if ($numbers === []) {
                return $this->result('error', "{$number} is not a number in the connected Twilio account.");
            }

            $webhook = rtrim($runtimeUrl, '/') . '/twilio/incoming';
            $twilio->incomingPhoneNumbers($numbers[0]->sid)->update([
                'voiceUrl' => $webhook,
                'voiceMethod' => 'POST',
            ]);

            return $this->result('wired', "Calls to {$number} reach this agent.", $webhook);
        } catch (ValidationException $e) {
            // Expected, actionable misconfiguration — no need to page Sentry.
            return $this->result('error', $e->getMessage());
        } catch (Throwable $e) {
            captureException($e);

            return $this->result('error', 'Could not reach Twilio to set the inbound webhook.');
        }
    }

    /**
     * @param array{status: string, message: string, url: string|null} $result
     */
    private function persistStatus(array $result): void
    {
        $voice = $this->agent->voice_config ?? [];
        $voice['inbound_webhook'] = $result + ['at' => now()->toIso8601String()];

        // Direct, quiet write — this is system metadata, not a user edit, and
        // must not re-trigger observers or the save mutation.
        $this->agent->voice_config = $voice;
        $this->agent->saveQuietly();
    }

    /**
     * @param array{status: string, message: string, url: string|null} $result
     */
    private function log(array $result): void
    {
        $line = "voice inbound webhook [{$result['status']}] agent {$this->agent->uuid}: {$result['message']}";
        if ($result['status'] === 'error') {
            logger()->warning($line);
        } else {
            logger()->info($line);
        }
    }

    /**
     * @return array{status: string, message: string, url: string|null}
     */
    private function result(string $status, string $message, ?string $url = null): array
    {
        return ['status' => $status, 'message' => $message, 'url' => $url];
    }
}
