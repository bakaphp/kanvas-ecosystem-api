<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations\FollowUp;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\FollowUp\Actions\CreateFollowUpDayAction;
use Kanvas\Intelligence\FollowUp\Actions\UpdateFollowUpDayAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpDay as FollowUpDayData;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;

/**
 * @deprecated GraphQL resolver for the legacy follow-up day CRUD. v1 reads
 *             stage.config.follow_up.time_based directly. Slated for deletion —
 *             see docs/intelligence/follow-up-deprecation-spec.md kill list.
 */
class FollowUpDayMutation
{
    public function create(mixed $rootValue, array $request): FollowUpDay
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $input = $request['input'];

        $followUp = FollowUp::getByIdFromCompanyApp((int) $input['follow_up_id'], $company, $app);
        $pipelineStage = PipelineStage::getById((int) $input['pipeline_stage_id'], $app);

        return new CreateFollowUpDayAction(
            new FollowUpDayData(
                followUp: $followUp,
                pipelineStage: $pipelineStage,
                name: $input['name'],
                time_value: (int) $input['time_value'],
                weight: (int) $input['weight'],
                calendar_day: (bool) ($input['calendar_day'] ?? true),
                send_message: (bool) ($input['send_message'] ?? false),
                time_unit: $input['time_unit'] ?? null,
                move_to_stage_id: isset($input['move_to_stage_id']) ? (int) $input['move_to_stage_id'] : null,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): FollowUpDay
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $input = $request['input'];

        $followUpDay = FollowUpDay::getById((int) $request['id'], $app);
        $pipelineStage = PipelineStage::getById(
            (int) ($input['pipeline_stage_id'] ?? $followUpDay->pipeline_stages_id),
            $app
        );
        $followUp = FollowUp::getByIdFromCompanyApp($followUpDay->follow_ups_id, $company, $app);

        return new UpdateFollowUpDayAction(
            $followUpDay,
            new FollowUpDayData(
                followUp: $followUp,
                pipelineStage: $pipelineStage,
                name: $input['name'] ?? $followUpDay->name,
                time_value: isset($input['time_value']) ? (int) $input['time_value'] : $followUpDay->time_value,
                weight: isset($input['weight']) ? (int) $input['weight'] : $followUpDay->weight,
                calendar_day: isset($input['calendar_day']) ? (bool) $input['calendar_day'] : (bool) $followUpDay->calendar_day,
                send_message: isset($input['send_message']) ? (bool) $input['send_message'] : (bool) $followUpDay->send_message,
                time_unit: array_key_exists('time_unit', $input) ? $input['time_unit'] : $followUpDay->time_unit,
                move_to_stage_id: array_key_exists('move_to_stage_id', $input)
                    ? (isset($input['move_to_stage_id']) ? (int) $input['move_to_stage_id'] : null)
                    : $followUpDay->move_to_stage_id,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $followUpDay = FollowUpDay::getById((int) $request['id'], app(Apps::class));

        return (bool) $followUpDay->delete();
    }
}
