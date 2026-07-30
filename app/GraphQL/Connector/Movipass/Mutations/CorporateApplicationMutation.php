<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\Movipass\Actions\ApproveCorporateLeadAction;
use Kanvas\Connectors\Movipass\Actions\RejectCorporateLeadAction;
use Kanvas\Connectors\Movipass\Enums\CorporateApplicationStatusEnum;
use Kanvas\Connectors\Movipass\Enums\CorporateLeadFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

class CorporateApplicationMutation
{
    use ResolvesActingContext;

    public function approve(mixed $rootValue, array $request): array
    {
        $lead = $this->resolveApplication((int) $request['id']);

        return new ApproveCorporateLeadAction($lead, $this->actingContext()->user)->execute();
    }

    public function reject(mixed $rootValue, array $request): array
    {
        $reason = trim((string) $request['reason']);

        if ($reason === '') {
            throw new ValidationException('A rejection reason is required.');
        }

        $lead = $this->resolveApplication((int) $request['id']);

        return new RejectCorporateLeadAction($lead, $reason, $this->actingContext()->user)->execute();
    }

    /**
     * Rejection is terminal, approval is not: re-approving is harmless because the action is
     * idempotent, but reviving a rejected application would need the Company and invite it
     * never got, and flipping an approved one to rejected would leave both alive while the
     * lead claims otherwise.
     */
    private function resolveApplication(int $leadId): Lead
    {
        $ctx = $this->actingContext();

        /** @var Lead $lead */
        $lead = Lead::getByIdFromCompanyApp($leadId, $ctx->company, $ctx->app);

        $status = CorporateApplicationStatusEnum::tryFrom(
            (string) $lead->get(CorporateLeadFieldEnum::STATUS->value)
        );

        if ($status === null) {
            throw new ValidationException('This lead is not a corporate application.');
        }

        if ($status === CorporateApplicationStatusEnum::REJECTED) {
            throw new ValidationException('This application was already rejected.');
        }

        return $lead;
    }
}
