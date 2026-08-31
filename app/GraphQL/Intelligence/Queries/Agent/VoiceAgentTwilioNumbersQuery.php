<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\Agent;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Throwable;

/**
 * List the AVAILABLE Twilio numbers on the current company's connected Twilio
 * account — i.e. numbers you've already purchased that are NOT yet assigned to
 * another agent — so the admin can PICK one when configuring an agent's voice
 * instead of typing it. Uses the company's Twilio creds via the existing
 * connector (same source the inbound-webhook wiring uses).
 *
 * Pass `agent_uuid` when editing an agent so its OWN currently-assigned number
 * stays in the list (only numbers claimed by OTHER agents are filtered out).
 *
 * @guard (user session). Best-effort: returns an empty list when the company
 * has no Twilio creds or the Twilio API can't be reached, so the UI shows
 * "no numbers" rather than erroring.
 */
class VoiceAgentTwilioNumbersQuery
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(mixed $root, array $args): array
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $exceptUuid = $args['agent_uuid'] ?? null;

        // Numbers already claimed by OTHER agents (digits-only, for lenient match).
        $assigned = [];
        $agents = Agent::query()
            ->notDeleted()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereNotNull('voice_config')
            ->get();
        foreach ($agents as $agent) {
            if ($exceptUuid !== null && $agent->uuid === $exceptUuid) {
                continue; // keep the agent-being-edited's own number available
            }
            $number = $agent->voice_config['phone_number'] ?? null;
            if (! empty($number)) {
                $assigned[AgentsRepository::normalizePhoneNumber((string) $number)] = true;
            }
        }

        try {
            $twilio = TwilioClient::getInstanceByCompany($company);
            $numbers = $twilio->incomingPhoneNumbers->read([], 200);
        } catch (Throwable) {
            return []; // no creds / API error — surface as an empty picker
        }

        $available = [];
        foreach ($numbers as $n) {
            $normalized = AgentsRepository::normalizePhoneNumber((string) $n->phoneNumber);
            if (isset($assigned[$normalized])) {
                continue; // already in use by another agent
            }
            $available[] = [
                'phone_number' => $n->phoneNumber,
                'friendly_name' => $n->friendlyName,
                'sid' => $n->sid,
            ];
        }

        return $available;
    }
}
