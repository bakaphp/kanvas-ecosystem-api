<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Tests\TestCase;

class FollowUpCrudTest extends TestCase
{
    private function getPipelineAndStage(): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $pipeline = Pipeline::fromCompany($company)->fromApp($app)->notDeleted()->first();

        if (! $pipeline) {
            $pipeline = Pipeline::create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'users_id' => auth()->id(),
                'system_modules_id' => 0,
                'name' => 'Test Pipeline ' . fake()->word(),
                'weight' => 0,
                'is_default' => 0,
            ]);
        }

        $stage = PipelineStage::where('pipelines_id', $pipeline->getId())->notDeleted()->first();

        if (! $stage) {
            $stage = PipelineStage::create([
                'pipelines_id' => $pipeline->getId(),
                'name' => 'Test Stage',
                'weight' => 1,
            ]);
        }

        return [
            'pipeline_id' => (string) $pipeline->getId(),
            'pipeline_stage_id' => (string) $stage->getId(),
        ];
    }

    public function testCreateFollowUp(): void
    {
        ['pipeline_id' => $pipelineId] = $this->getPipelineAndStage();

        $input = [
            'pipeline_id' => $pipelineId,
            'name' => 'Test Follow Up ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ];

        $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) {
                    id
                    name
                    follow_up_type
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createFollowUp' => [
                    'name' => $input['name'],
                    'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
                ],
            ],
        ]);
    }

    public function testCreateFollowUpWithConfig(): void
    {
        ['pipeline_id' => $pipelineId] = $this->getPipelineAndStage();

        $input = [
            'pipeline_id' => $pipelineId,
            'name' => 'Follow Up Config ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
            'config' => [
                'channels_available' => ['sms', 'email'],
            ],
        ];

        $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) {
                    id
                    name
                    config
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createFollowUp' => [
                    'name' => $input['name'],
                ],
            ],
        ]);
    }

    public function testUpdateFollowUp(): void
    {
        ['pipeline_id' => $pipelineId] = $this->getPipelineAndStage();

        $createResponse = $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) { id name }
            }
        ', ['input' => [
            'pipeline_id' => $pipelineId,
            'name' => 'Original ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ]])->assertSuccessful();

        $id = $createResponse->json('data.createFollowUp.id');

        $this->graphQL('
            mutation($id: ID!, $input: UpdateFollowUpInput!) {
                updateFollowUp(id: $id, input: $input) {
                    id
                    name
                    follow_up_type
                }
            }
        ', [
            'id' => $id,
            'input' => [
                'name' => 'Updated Follow Up',
                'follow_up_type' => FollowUpTypeEnum::SOLD_LEAD_FOLLOW_UP->value,
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateFollowUp' => [
                    'name' => 'Updated Follow Up',
                    'follow_up_type' => FollowUpTypeEnum::SOLD_LEAD_FOLLOW_UP->value,
                ],
            ],
        ]);
    }

    public function testDeleteFollowUp(): void
    {
        ['pipeline_id' => $pipelineId] = $this->getPipelineAndStage();

        $createResponse = $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) { id }
            }
        ', ['input' => [
            'pipeline_id' => $pipelineId,
            'name' => 'Delete Test ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ]])->assertSuccessful();

        $id = $createResponse->json('data.createFollowUp.id');

        $this->graphQL('
            mutation($id: ID!) { deleteFollowUp(id: $id) }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteFollowUp' => true]]);
    }

    public function testListFollowUps(): void
    {
        ['pipeline_id' => $pipelineId] = $this->getPipelineAndStage();

        $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) { id }
            }
        ', ['input' => [
            'pipeline_id' => $pipelineId,
            'name' => 'List Test ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ]])->assertSuccessful();

        $this->graphQL('query { followUps { data { id name follow_up_type } } }')
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => ['followUps' => ['data' => [['id', 'name', 'follow_up_type']]]],
            ]);
    }

    public function testCreateFollowUpDay(): void
    {
        ['pipeline_id' => $pipelineId, 'pipeline_stage_id' => $stageId] = $this->getPipelineAndStage();

        $createFollowUp = $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) { id }
            }
        ', ['input' => [
            'pipeline_id' => $pipelineId,
            'name' => 'FU for Days ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ]])->assertSuccessful();

        $followUpId = $createFollowUp->json('data.createFollowUp.id');

        $this->graphQL('
            mutation($input: FollowUpDayInput!) {
                createFollowUpDay(input: $input) {
                    id
                    name
                    time_value
                    time_unit
                    weight
                    calendar_day
                    send_message
                }
            }
        ', ['input' => [
            'follow_up_id' => $followUpId,
            'pipeline_stage_id' => $stageId,
            'name' => 'Day 1',
            'time_value' => 60,
            'time_unit' => 'minutes',
            'weight' => 1,
            'send_message' => true,
        ]])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createFollowUpDay' => [
                    'name' => 'Day 1',
                    'time_value' => 60,
                    'time_unit' => 'minutes',
                    'weight' => 1,
                    'send_message' => true,
                ],
            ],
        ]);
    }

    public function testUpdateFollowUpDay(): void
    {
        ['pipeline_id' => $pipelineId, 'pipeline_stage_id' => $stageId] = $this->getPipelineAndStage();

        $followUpId = $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) { id }
            }
        ', ['input' => [
            'pipeline_id' => $pipelineId,
            'name' => 'FU Days Update ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ]])->assertSuccessful()->json('data.createFollowUp.id');

        $dayId = $this->graphQL('
            mutation($input: FollowUpDayInput!) {
                createFollowUpDay(input: $input) { id }
            }
        ', ['input' => [
            'follow_up_id' => $followUpId,
            'pipeline_stage_id' => $stageId,
            'name' => 'Day Original',
            'time_value' => 30,
            'weight' => 1,
        ]])->assertSuccessful()->json('data.createFollowUpDay.id');

        $this->graphQL('
            mutation($id: ID!, $input: UpdateFollowUpDayInput!) {
                updateFollowUpDay(id: $id, input: $input) {
                    id
                    name
                    time_value
                }
            }
        ', [
            'id' => $dayId,
            'input' => [
                'name' => 'Day Updated',
                'time_value' => 120,
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateFollowUpDay' => [
                    'name' => 'Day Updated',
                    'time_value' => 120,
                ],
            ],
        ]);
    }

    public function testDeleteFollowUpDay(): void
    {
        ['pipeline_id' => $pipelineId, 'pipeline_stage_id' => $stageId] = $this->getPipelineAndStage();

        $followUpId = $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) { id }
            }
        ', ['input' => [
            'pipeline_id' => $pipelineId,
            'name' => 'FU Day Delete ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ]])->assertSuccessful()->json('data.createFollowUp.id');

        $dayId = $this->graphQL('
            mutation($input: FollowUpDayInput!) {
                createFollowUpDay(input: $input) { id }
            }
        ', ['input' => [
            'follow_up_id' => $followUpId,
            'pipeline_stage_id' => $stageId,
            'name' => 'Day to Delete',
            'time_value' => 60,
            'weight' => 1,
        ]])->assertSuccessful()->json('data.createFollowUpDay.id');

        $this->graphQL('
            mutation($id: ID!) { deleteFollowUpDay(id: $id) }
        ', ['id' => $dayId])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteFollowUpDay' => true]]);
    }

    public function testCreateFollowUpTemplate(): void
    {
        ['pipeline_id' => $pipelineId, 'pipeline_stage_id' => $stageId] = $this->getPipelineAndStage();

        $followUpId = $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) { id }
            }
        ', ['input' => [
            'pipeline_id' => $pipelineId,
            'name' => 'FU Template ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ]])->assertSuccessful()->json('data.createFollowUp.id');

        $dayId = $this->graphQL('
            mutation($input: FollowUpDayInput!) {
                createFollowUpDay(input: $input) { id }
            }
        ', ['input' => [
            'follow_up_id' => $followUpId,
            'pipeline_stage_id' => $stageId,
            'name' => 'Day 1',
            'time_value' => 60,
            'weight' => 1,
        ]])->assertSuccessful()->json('data.createFollowUpDay.id');

        $this->graphQL('
            mutation($input: FollowUpTemplateInput!) {
                createFollowUpTemplate(input: $input) {
                    id
                    communication_channel
                    name
                    template
                }
            }
        ', ['input' => [
            'follow_up_day_id' => $dayId,
            'communication_channel' => 'sms',
            'name' => 'SMS Day 1',
            'template' => 'Hi {{ $lead->firstname }}, just following up!',
        ]])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createFollowUpTemplate' => [
                    'communication_channel' => 'sms',
                    'name' => 'SMS Day 1',
                ],
            ],
        ]);
    }

    public function testUpdateFollowUpTemplate(): void
    {
        ['pipeline_id' => $pipelineId, 'pipeline_stage_id' => $stageId] = $this->getPipelineAndStage();

        $followUpId = $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) { id }
            }
        ', ['input' => [
            'pipeline_id' => $pipelineId,
            'name' => 'FU Template Update ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ]])->assertSuccessful()->json('data.createFollowUp.id');

        $dayId = $this->graphQL('
            mutation($input: FollowUpDayInput!) {
                createFollowUpDay(input: $input) { id }
            }
        ', ['input' => [
            'follow_up_id' => $followUpId,
            'pipeline_stage_id' => $stageId,
            'name' => 'Day 1',
            'time_value' => 60,
            'weight' => 1,
        ]])->assertSuccessful()->json('data.createFollowUpDay.id');

        $templateId = $this->graphQL('
            mutation($input: FollowUpTemplateInput!) {
                createFollowUpTemplate(input: $input) { id }
            }
        ', ['input' => [
            'follow_up_day_id' => $dayId,
            'communication_channel' => 'sms',
            'name' => 'Original SMS',
            'template' => 'Hi {{ $lead->firstname }}',
        ]])->assertSuccessful()->json('data.createFollowUpTemplate.id');

        $this->graphQL('
            mutation($id: ID!, $input: UpdateFollowUpTemplateInput!) {
                updateFollowUpTemplate(id: $id, input: $input) {
                    id
                    name
                    template
                    communication_channel
                }
            }
        ', [
            'id' => $templateId,
            'input' => [
                'name' => 'Updated SMS',
                'template' => 'Hello {{ $lead->firstname }}, are you still interested?',
                'communication_channel' => 'email',
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateFollowUpTemplate' => [
                    'name' => 'Updated SMS',
                    'communication_channel' => 'email',
                ],
            ],
        ]);
    }

    public function testDeleteFollowUpTemplate(): void
    {
        ['pipeline_id' => $pipelineId, 'pipeline_stage_id' => $stageId] = $this->getPipelineAndStage();

        $followUpId = $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) { id }
            }
        ', ['input' => [
            'pipeline_id' => $pipelineId,
            'name' => 'FU Template Delete ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ]])->assertSuccessful()->json('data.createFollowUp.id');

        $dayId = $this->graphQL('
            mutation($input: FollowUpDayInput!) {
                createFollowUpDay(input: $input) { id }
            }
        ', ['input' => [
            'follow_up_id' => $followUpId,
            'pipeline_stage_id' => $stageId,
            'name' => 'Day 1',
            'time_value' => 60,
            'weight' => 1,
        ]])->assertSuccessful()->json('data.createFollowUpDay.id');

        $templateId = $this->graphQL('
            mutation($input: FollowUpTemplateInput!) {
                createFollowUpTemplate(input: $input) { id }
            }
        ', ['input' => [
            'follow_up_day_id' => $dayId,
            'communication_channel' => 'sms',
            'name' => 'SMS to Delete',
            'template' => 'Hi {{ $lead->firstname }}',
        ]])->assertSuccessful()->json('data.createFollowUpTemplate.id');

        $this->graphQL('
            mutation($id: ID!) { deleteFollowUpTemplate(id: $id) }
        ', ['id' => $templateId])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteFollowUpTemplate' => true]]);
    }

    public function testListFollowUpDays(): void
    {
        ['pipeline_id' => $pipelineId, 'pipeline_stage_id' => $stageId] = $this->getPipelineAndStage();

        $followUpId = $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) { id }
            }
        ', ['input' => [
            'pipeline_id' => $pipelineId,
            'name' => 'FU List Days ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ]])->assertSuccessful()->json('data.createFollowUp.id');

        $this->graphQL('
            mutation($input: FollowUpDayInput!) {
                createFollowUpDay(input: $input) { id }
            }
        ', ['input' => [
            'follow_up_id' => $followUpId,
            'pipeline_stage_id' => $stageId,
            'name' => 'Day 1',
            'time_value' => 60,
            'weight' => 1,
        ]])->assertSuccessful();

        $this->graphQL('
            query($followUpId: Mixed) {
                followUpDays(where: { column: FOLLOW_UPS_ID, operator: EQ, value: $followUpId }) {
                    data { id name time_value weight }
                }
            }
        ', ['followUpId' => $followUpId])
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => ['followUpDays' => ['data' => [['id', 'name', 'time_value', 'weight']]]],
        ]);
    }

    public function testListFollowUpTemplates(): void
    {
        ['pipeline_id' => $pipelineId, 'pipeline_stage_id' => $stageId] = $this->getPipelineAndStage();

        $followUpId = $this->graphQL('
            mutation($input: FollowUpInput!) {
                createFollowUp(input: $input) { id }
            }
        ', ['input' => [
            'pipeline_id' => $pipelineId,
            'name' => 'FU List Templates ' . fake()->word(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
        ]])->assertSuccessful()->json('data.createFollowUp.id');

        $dayId = $this->graphQL('
            mutation($input: FollowUpDayInput!) {
                createFollowUpDay(input: $input) { id }
            }
        ', ['input' => [
            'follow_up_id' => $followUpId,
            'pipeline_stage_id' => $stageId,
            'name' => 'Day 1',
            'time_value' => 60,
            'weight' => 1,
        ]])->assertSuccessful()->json('data.createFollowUpDay.id');

        $this->graphQL('
            mutation($input: FollowUpTemplateInput!) {
                createFollowUpTemplate(input: $input) { id }
            }
        ', ['input' => [
            'follow_up_day_id' => $dayId,
            'communication_channel' => 'sms',
            'name' => 'SMS Template',
            'template' => 'Hi {{ $lead->firstname }}',
        ]])->assertSuccessful();

        $this->graphQL('
            query($dayId: Mixed) {
                followUpTemplates(where: { column: FOLLOW_UP_DAYS_ID, operator: EQ, value: $dayId }) {
                    data { id name communication_channel template }
                }
            }
        ', ['dayId' => $dayId])
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => ['followUpTemplates' => ['data' => [['id', 'name', 'communication_channel', 'template']]]],
        ]);
    }
}
