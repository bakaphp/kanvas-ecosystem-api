<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Twilio\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\ListCompanyPhoneNumbersAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Throwable;

/**
 * Best-effort: an empty list when the company has no Twilio credentials or the API is unreachable,
 * so the agent number picker shows "no numbers" rather than an error.
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

        $exceptAgentId = isset($args['id']) ? (int) $args['id'] : null;
        $assigned = $this->numbersAssignedToOtherAgents($ctx->app, $ctx->company, $exceptAgentId);

        return array_values(array_filter(
            $numbers,
            static fn (array $n): bool => ! isset(
                $assigned[AgentsRepository::normalizePhoneNumber((string) $n['phone_number'])]
            ),
        ));
    }

    /**
     * @return array<string, true> keyed by normalized number
     */
    private function numbersAssignedToOtherAgents(Apps $app, CompanyInterface $company, ?int $exceptAgentId): array
    {
        $assigned = [];
        $agents = Agent::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where(fn ($query) => $query->whereNotNull('voice_config')->orWhereNotNull('sms_config'))
            ->when($exceptAgentId !== null, fn ($query) => $query->where('id', '!=', $exceptAgentId))
            ->get(['id', 'voice_config', 'sms_config']);

        foreach ($agents as $agent) {
            foreach ([$agent->voice_config['phone_number'] ?? null, $agent->smsPhoneNumber()] as $number) {
                if (! empty($number)) {
                    $assigned[AgentsRepository::normalizePhoneNumber((string) $number)] = true;
                }
            }
        }

        return $assigned;
    }
}
