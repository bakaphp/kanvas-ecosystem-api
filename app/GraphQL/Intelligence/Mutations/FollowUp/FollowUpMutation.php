<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations\FollowUp;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\FollowUp\Actions\CreateFollowUpAction;
use Kanvas\Intelligence\FollowUp\Actions\UpdateFollowUpAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUp as FollowUpData;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;

/**
 * @deprecated GraphQL resolver for the legacy follow-up CRUD. v1 reads
 *             stage.config.follow_up directly. Mutations marked
 *             @deprecated in graphql/schemas/Intelligence/followUp.graphql.
 *             Slated for deletion — see docs/intelligence/follow-up-deprecation-spec.md.
 */
class FollowUpMutation
{
    public function create(mixed $rootValue, array $request): FollowUp
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        $pipeline = Pipeline::getByIdFromCompanyApp((int) $input['pipeline_id'], $company, $app);

        return new CreateFollowUpAction(
            new FollowUpData(
                app: $app,
                company: $company,
                user: $user,
                pipeline: $pipeline,
                name: $input['name'],
                follow_up_type: FollowUpTypeEnum::from((int) $input['follow_up_type']),
                config: $input['config'] ?? null,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): FollowUp
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        $followUp = FollowUp::getByIdFromCompanyApp((int) $request['id'], $company, $app);
        $pipeline = Pipeline::getByIdFromCompanyApp(
            (int) ($input['pipeline_id'] ?? $followUp->pipelines_id),
            $company,
            $app
        );

        return new UpdateFollowUpAction(
            $followUp,
            new FollowUpData(
                app: $app,
                company: $company,
                user: $user,
                pipeline: $pipeline,
                name: $input['name'] ?? $followUp->name,
                follow_up_type: isset($input['follow_up_type'])
                    ? FollowUpTypeEnum::from((int) $input['follow_up_type'])
                    : FollowUpTypeEnum::from($followUp->follow_up_type),
                config: array_key_exists('config', $input) ? $input['config'] : $followUp->config,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $followUp = FollowUp::getByIdFromCompanyApp(
            (int) $request['id'],
            auth()->user()->getCurrentCompany(),
            app(Apps::class)
        );

        return (bool) $followUp->delete();
    }
}
