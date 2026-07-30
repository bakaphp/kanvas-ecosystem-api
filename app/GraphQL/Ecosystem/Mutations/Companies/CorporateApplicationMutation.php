<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\CorporateApplications\Actions\ApproveCorporateApplicationAction;
use Kanvas\Companies\CorporateApplications\Actions\RejectCorporateApplicationAction;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Applications are carried on Leads today. That binding lives here rather than in the
 * actions, which stay entity-agnostic.
 */
class CorporateApplicationMutation
{
    use ResolvesActingContext;

    public function approve(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        return new ApproveCorporateApplicationAction(
            $this->resolveApplication((int) $request['id']),
            $ctx->app,
            $ctx->user,
        )->execute();
    }

    public function reject(mixed $rootValue, array $request): array
    {
        $reason = trim((string) $request['reason']);

        if ($reason === '') {
            throw new ValidationException('A rejection reason is required.');
        }

        $ctx = $this->actingContext();

        return new RejectCorporateApplicationAction(
            $this->resolveApplication((int) $request['id']),
            $ctx->app,
            $reason,
            $ctx->user,
        )->execute();
    }

    /**
     * Rejection is terminal, approval is not: re-approving is harmless because the action is
     * idempotent, but reviving a rejected application would need the Company and invite it
     * never got, and flipping an approved one to rejected would leave both alive while the
     * application claims otherwise.
     */
    private function resolveApplication(int $id): Model
    {
        $ctx = $this->actingContext();

        /** @var Lead $application */
        $application = Lead::getByIdFromCompanyApp($id, $ctx->company, $ctx->app);

        $status = CorporateApplicationStatusEnum::tryFrom(
            (string) Field::STATUS->readFrom($application)
        );

        if ($status === null) {
            throw new ValidationException('This lead is not a corporate application.');
        }

        if ($status === CorporateApplicationStatusEnum::REJECTED) {
            throw new ValidationException('This application was already rejected.');
        }

        return $application;
    }
}
