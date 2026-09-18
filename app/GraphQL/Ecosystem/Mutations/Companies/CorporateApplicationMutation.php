<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use App\GraphQL\Concerns\ActingContext;
use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\CorporateApplications\Actions\ApproveCorporateApplicationAction;
use Kanvas\Companies\CorporateApplications\Actions\RejectCorporateApplicationAction;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

class CorporateApplicationMutation
{
    use ResolvesActingContext;

    public function approve(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        return new ApproveCorporateApplicationAction($this->resolveApplication((int) $request['id'], $ctx), $ctx->app, $ctx->user)->execute();
    }

    public function reject(mixed $rootValue, array $request): array
    {
        $reason = trim((string) $request['reason']);

        if ($reason === '') {
            throw new ValidationException('A rejection reason is required.');
        }

        $ctx = $this->actingContext();

        return new RejectCorporateApplicationAction(
            $this->resolveApplication((int) $request['id'], $ctx),
            $ctx->app,
            $reason,
            $ctx->user,
        )->execute();
    }

    private function resolveApplication(int $id, ActingContext $ctx): Model
    {
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
