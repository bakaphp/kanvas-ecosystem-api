<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
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
 * BEST-EFFORT by design: it is called on agent save and must NEVER throw or block
 * the save. It quietly skips when it can't act (no per-agent number, no runtime
 * URL configured, no company/Twilio creds, or the number isn't in that Twilio
 * account) and reports unexpected failures to Sentry.
 */
class ConfigureAgentInboundWebhookAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly AppInterface $app,
    ) {
    }

    /**
     * @return bool true if the webhook was set, false if it skipped or failed
     */
    public function execute(): bool
    {
        try {
            $voice = $this->agent->voice_config ?? [];
            $number = trim((string) ($voice['phone_number'] ?? ''));
            if ($number === '') {
                return false; // no per-agent number → nothing to wire
            }

            // Global runtime URL by default (one Cloud Run for every app);
            // a per-app setting still overrides. See VoiceRuntimeConfig.
            $runtimeUrl = VoiceRuntimeConfig::url($this->app);
            if ($runtimeUrl === '') {
                return false; // runtime not configured
            }

            $company = $this->agent->companies_id > 0
                ? Companies::find($this->agent->companies_id)
                : null;
            if ($company === null) {
                return false;
            }

            $twilio = TwilioClient::getInstanceByCompany($company);

            // Resolve the number's SID; if the account doesn't own it, skip.
            $numbers = $twilio->incomingPhoneNumbers->read(['phoneNumber' => $number], 1);
            if ($numbers === []) {
                return false;
            }

            $twilio->incomingPhoneNumbers($numbers[0]->sid)->update([
                'voiceUrl' => rtrim($runtimeUrl, '/') . '/twilio/incoming',
                'voiceMethod' => 'POST',
            ]);

            return true;
        } catch (Throwable $e) {
            // Inbound wiring is a convenience; never let it break saving the agent.
            captureException($e);

            return false;
        }
    }
}
