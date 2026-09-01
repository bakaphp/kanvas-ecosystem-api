<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Twilio\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\Twilio\Actions\ListCompanyPhoneNumbersAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Throwable;

/**
 * List the company's AVAILABLE Twilio numbers — purchased on its Twilio account
 * but NOT yet assigned to another voice agent — for the agent voice-config
 * number picker.
 *
 * The third-party Twilio call is owned by the connector
 * (ListCompanyPhoneNumbersAction); this resolver only composes it with the
 * voice-agent assignment filter. Pass `agent_uuid` when editing an agent so its
 * own currently-assigned number stays listed.
 *
 * @guard (user session). Best-effort: empty list when the company has no Twilio
 * creds or the API is unreachable, so the UI shows "no numbers" not an error.
 */
class TwilioPhoneNumbersQuery
{
    use ResolvesActingContext;

    /**
     * @param array<string, mixed> $args
     *
     * @return array<int, array<string, mixed>>
     */
    public function available(mixed $root, array $args): array
    {
        $ctx = $this->actingContext();

        try {
            $numbers = new ListCompanyPhoneNumbersAction($ctx->company)->execute();
        } catch (Throwable) {
            return [];
        }

        $assigned = $this->numbersAssignedToOtherAgents($ctx->app, $ctx->company, $args['agent_uuid'] ?? null);

        return array_values(array_filter(
            $numbers,
            static fn (array $n): bool => ! isset(
                $assigned[AgentsRepository::normalizePhoneNumber((string) $n['phone_number'])]
            ),
        ));
    }

    /**
     * Digits-only phone numbers already claimed by another agent's voice_config,
     * keyed for O(1) lookup. Excludes `$exceptUuid` (the agent being edited).
     *
     * @return array<string, true>
     */
    private function numbersAssignedToOtherAgents(mixed $app, mixed $company, ?string $exceptUuid): array
    {
        $assigned = [];
        $agents = Agent::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->whereNotNull('voice_config')
            ->get();

        foreach ($agents as $agent) {
            if ($exceptUuid !== null && $agent->uuid === $exceptUuid) {
                continue;
            }
            $number = $agent->voice_config['phone_number'] ?? null;
            if (! empty($number)) {
                $assigned[AgentsRepository::normalizePhoneNumber((string) $number)] = true;
            }
        }

        return $assigned;
    }
}
