<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Deal;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Guild\Deals\Actions\CreateDealAction;
use Kanvas\Guild\Deals\Actions\UpdateDealAction;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

class DealMutation
{
    use HasMutationUploadFiles;

    public function create(mixed $rootValue, array $request): Deal
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        return new CreateDealAction(
            DealData::fromMultiple($user, $app, $company, $request['input'])
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Deal
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var Deal $deal */
        $deal = Deal::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateDealAction(
            $deal,
            DealData::fromMultiple($user, $app, $company, $request['input'], $deal)
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var Deal $deal */
        $deal = Deal::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $deal->fireWorkflow(WorkflowEnum::DELETED->value, true);

        return $deal->softDelete();
    }

    public function attachFile(mixed $rootValue, array $request): Deal
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var Deal $deal */
        $deal = Deal::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $rawParams = $request['params'] ?? null;
        /** @var array<string, mixed> $params */
        $params = is_array($rawParams) ? $rawParams : [];
        $taskId = $params['task_id'] ?? null;

        if (is_string($taskId) && $taskId !== '' || is_int($taskId)) {
            $deal->set('checklist_upload', $taskId);
            $deal->fireWorkflow(
                WorkflowEnum::AFTER_UPLOAD->value,
                true,
                [
                    'task_id' => $taskId,
                    'app' => $app,
                ]
            );
        }

        $this->uploadFileToEntity(
            model: $deal,
            app: $app,
            user: $user,
            request: $request
        );

        return $deal;
    }
}
