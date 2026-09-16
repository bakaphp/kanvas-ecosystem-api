<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Throwable;

use function Sentry\captureException;

/**
 * Release a Twilio number's inbound webhook when an agent stops using it.
 *
 * The counterpart to ConfigureAgentInboundWebhookAction: when an agent changes
 * (or clears) its phone number, that action wires the NEW number but nothing
 * clears the OLD one, leaving a stale `voiceUrl` pointing at the runtime. This
 * clears it — but ONLY when no voice agent anywhere still owns the number.
 *
 * The ownership check is ACCOUNT-WIDE on purpose: voice agents share one Twilio
 * account, and a phone-number string belongs to exactly one account, so any
 * agent in ANY app still carrying that number means the webhook is in use and
 * must be left in place (clearing it would kill that agent's inbound). Scanning
 * all voice-configured agents is cheap — the set is small.
 *
 * NON-BLOCKING and NEVER throws: it runs on agent save and must not break it.
 * Returns a structured outcome the caller can log.
 */
class ReleaseInboundWebhookForNumberAction
{
    public function __construct(
        private readonly string $number,
        private readonly AppInterface $app,
        private readonly ?Companies $company = null,
        // The agent that just moved off the number. It no longer carries it (the
        // update already applied), so this is belt-and-suspenders against a race
        // where the caller passes an agent still holding the old value.
        private readonly ?int $exceptAgentId = null,
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

        try {
            // Company creds if the company has its own; else the app-level creds
            // (the shared account the number lives on). Throws when neither is set.
            $twilio = $this->company !== null
                ? TwilioClient::getInstanceByAppOrCompany($this->app, $this->company)
                : TwilioClient::getInstance($this->app);

            $numbers = $twilio->incomingPhoneNumbers->read(['phoneNumber' => $number], 1);
            if ($numbers === []) {
                // Not on this account (maybe never wired, or a different account) —
                // nothing to clear.
                return $this->result('idle', "{$number} is not a number in the connected Twilio account.");
            }

            // Blank the voiceUrl so calls to this released number no longer reach
            // the runtime. Re-wired automatically if any agent claims it again.
            $twilio->incomingPhoneNumbers($numbers[0]->sid)->update([
                'voiceUrl' => '',
                'voiceMethod' => 'POST',
            ]);

            return $this->result('released', "Cleared the inbound webhook on {$number}.");
        } catch (ValidationException $e) {
            // Expected, actionable misconfiguration — no need to page Sentry.
            return $this->result('error', $e->getMessage());
        } catch (Throwable $e) {
            captureException($e);

            return $this->result('error', 'Could not reach Twilio to clear the inbound webhook.');
        }
    }

    /**
     * Whether ANY other voice agent (any app) still carries this number. A Twilio
     * number string is unique to one account, so a match anywhere means it's live.
     */
    private function stillOwned(string $number): bool
    {
        $normalized = AgentsRepository::normalizePhoneNumber($number);
        if ($normalized === '') {
            // Un-normalizable input — never risk clearing a webhook on a guess.
            return true;
        }

        $agents = Agent::query()
            ->notDeleted()
            ->whereNotNull('voice_config')
            ->get();

        foreach ($agents as $agent) {
            if ($this->exceptAgentId !== null && $agent->getId() === $this->exceptAgentId) {
                continue;
            }

            $stored = $agent->voice_config['phone_number'] ?? null;
            if (! empty($stored) && AgentsRepository::normalizePhoneNumber((string) $stored) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{status: string, message: string}
     */
    private function result(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }
}
