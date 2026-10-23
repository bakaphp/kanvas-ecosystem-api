<?php

declare(strict_types=1);

namespace App\GraphQL\Insurance\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Insurance\Actions\CreateInsurerAction;
use Kanvas\Insurance\Actions\UpdateInsurerAction;
use Kanvas\Insurance\DataTransferObject\Insurer as InsurerData;
use Kanvas\Insurance\Models\Insurer;

class InsurerMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): Insurer
    {
        $ctx = $this->actingContext();

        return new CreateInsurerAction(
            InsurerData::from($ctx->app, $ctx->company, $request['input']),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Insurer
    {
        $ctx = $this->actingContext();
        $insurer = Insurer::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new UpdateInsurerAction(
            $insurer,
            InsurerData::forUpdate($insurer, $request['input']),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();

        return Insurer::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app)->delete();
    }
}
