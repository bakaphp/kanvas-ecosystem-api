<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Deals\Actions\CreateDealAction;
use Kanvas\Guild\Deals\Actions\RecordDealNoteAction;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Users\Models\Users;
use Throwable;

trait CreatesDealTrait
{
    /**
     * @return array<string, mixed>
     */
    protected function createDeal(
        AppInterface $app,
        CompanyInterface $company,
        UserInterface $user,
        string $title,
        ?string $description = null,
        ?int $leadId = null,
        ?int $peopleId = null,
        ?int $organizationId = null,
        ?int $ownerId = null,
        ?int $pipelineId = null,
        ?int $pipelineStageId = null,
    ): array {
        // fromMultiple resolves each FK by id and throws on a hallucinated one — keep the error in-band.
        try {
            $request = ['title' => $title];

            if (filled($description)) {
                $request['description'] = $description;
            }
            if ($leadId !== null && $leadId > 0) {
                $request['leads_id'] = $leadId;
            }
            if ($peopleId !== null && $peopleId > 0) {
                $request['people_id'] = $peopleId;
            }
            if ($organizationId !== null && $organizationId > 0) {
                $request['organization_id'] = $organizationId;
            }
            if ($ownerId !== null && $ownerId > 0) {
                $request['owner_id'] = $ownerId;
            }
            if ($pipelineId !== null && $pipelineId > 0) {
                $request['pipeline_id'] = $pipelineId;
            }
            if ($pipelineStageId !== null && $pipelineStageId > 0) {
                $request['pipeline_stage_id'] = $pipelineStageId;
            }

            $deal = new CreateDealAction(
                DealData::fromMultiple($user, $app, $company, $request),
            )->execute();

            new RecordDealNoteAction($deal)->execute(
                'Deal created.',
                'deal-create',
                $user instanceof Users ? $user : null,
            );
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => "Failed to create deal: {$e->getMessage()}",
            ];
        }

        return [
            'status' => 'success',
            'deal_id' => $deal->getId(),
            'title' => $deal->title,
            'lead_id' => $deal->leads_id,
            'message' => "Deal '{$deal->title}' created successfully.",
        ];
    }
}
