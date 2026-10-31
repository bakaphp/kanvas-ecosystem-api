<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Tests\TestCase;
use Tests\Traits\AssertsIsDefaultOrdering;

class PipelineTest extends TestCase
{
    use AssertsIsDefaultOrdering;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testGetPipeline(): void
    {
        $this->graphQL('
            query {
                pipelines {
                    data {
                        id
                        name
                    }
                }
            }')->assertOk();
    }

    public function testCreateStageSavesConfig(): void
    {
        $config = ['stage_meta' => ['step_key' => '6_business_hours']];

        $stage = $this->createStage($config);

        $this->assertSame($config, PipelineStage::find($stage['id'])->config);
    }

    public function testUpdateStageReplacesConfig(): void
    {
        $stage = $this->createStage(['stage_meta' => ['step_key' => 'old']]);
        $config = ['stage_meta' => ['step_key' => 'new']];

        $this->updateStage($stage, ['config' => $config]);

        $this->assertSame($config, PipelineStage::find($stage['id'])->config);
    }

    public function testUpdateStageWithoutConfigKeepsIt(): void
    {
        $config = ['stage_meta' => ['step_key' => 'keep']];
        $stage = $this->createStage($config);

        $this->updateStage($stage, []);

        $this->assertSame($config, PipelineStage::find($stage['id'])->config);
    }

    public function testUpdateStageWithNullConfigClearsIt(): void
    {
        $stage = $this->createStage(['stage_meta' => ['step_key' => 'remove']]);

        $this->updateStage($stage, ['config' => null]);

        $this->assertNull(PipelineStage::find($stage['id'])->getRawOriginal('config'));
    }

    public function testCreateStageWithoutConfigLeavesItEmpty(): void
    {
        $pipelineId = $this->createPipeline()->json('data.createPipeline.id');

        $response = $this->sendCreateStage([
            'pipeline_id' => $pipelineId,
            'name' => fake()->uuid(),
            'rotting_days' => 0,
            'weight' => 1,
        ]);

        $response->assertOk()->assertJsonMissingPath('errors');

        $stage = PipelineStage::find($response->json('data.createPipelineStage.id'));

        $this->assertNull($stage->getRawOriginal('config'));
    }

    public function testCreateStageRestoresSoftDeletedStageOfSameName(): void
    {
        $stage = $this->createStage(['stage_meta' => ['step_key' => 'old']]);
        $config = ['stage_meta' => ['step_key' => 'restored']];

        PipelineStage::where('id', $stage['id'])->update(['is_deleted' => 1]);

        $response = $this->sendCreateStage([
            'pipeline_id' => $stage['pipeline_id'],
            'name' => $stage['name'],
            'rotting_days' => 0,
            'weight' => 1,
            'config' => $config,
        ]);

        $response->assertOk()->assertJsonMissingPath('errors');

        $restored = PipelineStage::find($stage['id']);

        $this->assertEquals($stage['id'], $response->json('data.createPipelineStage.id'));
        $this->assertEquals(0, $restored->is_deleted);
        $this->assertSame($config, $restored->config);
    }

    public function testCreateStageWithZeroWeightTakesNextWeight(): void
    {
        $stage = $this->createStage([]);

        $response = $this->sendCreateStage([
            'pipeline_id' => $stage['pipeline_id'],
            'name' => fake()->uuid(),
            'rotting_days' => 0,
            'weight' => 0,
        ]);

        $this->assertSame($stage['weight'] + 1, $response->json('data.createPipelineStage.weight'));
    }

    public function testUpdateStageWithZeroWeightKeepsCurrentWeight(): void
    {
        $stage = $this->createStage([]);

        $this->updateStage($stage, ['weight' => 0]);

        $this->assertEquals($stage['weight'], PipelineStage::find($stage['id'])->weight);
    }

    public function testUpdateStageRejectsNameUsedByAnotherStage(): void
    {
        $stage = $this->createStage([]);
        $other = fake()->uuid();

        $this->sendCreateStage([
            'pipeline_id' => $stage['pipeline_id'],
            'name' => $other,
            'rotting_days' => 0,
            'weight' => 2,
        ])->assertOk()->assertJsonMissingPath('errors');

        $response = $this->sendUpdateStage($stage, ['name' => $other]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame($stage['name'], PipelineStage::find($stage['id'])->name);
    }

    public function testUpdatePipelineAndUpdateStageSaveConfigTheSameWay(): void
    {
        $stage = $this->createStage(['stage_meta' => ['step_key' => 'keep']]);

        $this->savePipelineStage($stage, [])->assertJsonMissingPath('errors');
        $this->assertSame(['stage_meta' => ['step_key' => 'keep']], PipelineStage::find($stage['id'])->config);

        $this->savePipelineStage($stage, ['config' => ['stage_meta' => ['step_key' => 'new']]])
            ->assertJsonMissingPath('errors');
        $this->assertSame(['stage_meta' => ['step_key' => 'new']], PipelineStage::find($stage['id'])->config);

        $this->savePipelineStage($stage, ['config' => null])->assertJsonMissingPath('errors');
        $this->assertNull(PipelineStage::find($stage['id'])->getRawOriginal('config'));
    }

    public function testUpdatePipelineMergesEntriesWithTheSameName(): void
    {
        $stage = $this->createStage([]);
        $entry = [
            'name' => $stage['name'],
            'rotting_days' => 0,
            'weight' => 1,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    id
                }
            }
        ', [
            'id' => $stage['pipeline_id'],
            'input' => [
                'name' => Pipeline::find($stage['pipeline_id'])->name,
                'weight' => 0,
                'is_default' => false,
                'stages' => [$entry, $entry],
            ],
        ])->assertJsonMissingPath('errors');

        $this->assertSame(
            1,
            PipelineStage::where('pipelines_id', $stage['pipeline_id'])->where('name', $stage['name'])->count()
        );
    }

    public function testCreateStageWithExistingNameUpdatesIt(): void
    {
        $stage = $this->createStage(['stage_meta' => ['step_key' => 'original']]);
        $config = ['stage_meta' => ['step_key' => 'updated']];

        $response = $this->sendCreateStage([
            'pipeline_id' => $stage['pipeline_id'],
            'name' => $stage['name'],
            'rotting_days' => 0,
            'weight' => 1,
            'config' => $config,
        ]);

        $response->assertOk()->assertJsonMissingPath('errors');

        $this->assertEquals($stage['id'], $response->json('data.createPipelineStage.id'));
        $this->assertSame($config, PipelineStage::find($stage['id'])->config);
    }

    public function testUpdateStageReturnsSavedConfig(): void
    {
        $stage = $this->createStage(['stage_meta' => ['step_key' => 'old']]);
        $config = ['stage_meta' => ['step_key' => 'new']];

        $this->sendUpdateStage($stage, ['config' => $config])
            ->assertJsonPath('data.updatePipelineStage.config', $config);
    }

    public function testFollowUpEngineReadsConfigSavedThroughStageMutation(): void
    {
        $stage = $this->createStage([
            'follow_up' => [
                'enabled' => true,
                'mode' => 'time_based',
                'time_based' => ['interval_minutes' => 60],
                'max_retries' => 1,
                'exhausted_action' => 'stop',
                'channels' => [
                    ['type' => 'sms', 'enabled' => true, 'template_name' => 'value_add_day_1'],
                ],
            ],
        ]);

        $config = FollowUpConfig::fromStage(PipelineStage::find($stage['id']));

        $this->assertTrue($config->enabled);
        $this->assertSame(1, $config->maxRetries);
        $this->assertSame('value_add_day_1', $config->channelByType('sms')->templateName);
    }

    public function testCannotUpdateConfigOfAnotherCompanysStage(): void
    {
        $config = ['stage_meta' => ['step_key' => 'theirs']];
        $stage = $this->createStage($config);
        $otherCompanyId = auth()->user()->getCurrentCompany()->getId() + 100000;

        Pipeline::where('id', $stage['pipeline_id'])->update(['companies_id' => $otherCompanyId]);

        $response = $this->sendUpdateStage($stage, ['config' => ['stage_meta' => ['step_key' => 'hijacked']]]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame($config, PipelineStage::find($stage['id'])->config);
    }

    private function createStage(array $config): array
    {
        $pipelineId = $this->createPipeline()->json('data.createPipeline.id');

        $response = $this->sendCreateStage([
            'pipeline_id' => $pipelineId,
            'name' => fake()->uuid(),
            'rotting_days' => 0,
            'weight' => 1,
            'config' => $config,
        ]);

        $response->assertOk()->assertJsonMissingPath('errors');

        return [
            ...$response->json('data.createPipelineStage'),
            'pipeline_id' => $pipelineId,
        ];
    }

    private function updateStage(array $stage, array $input): void
    {
        $this->sendUpdateStage($stage, $input)->assertOk()->assertJsonMissingPath('errors');
    }

    private function sendCreateStage(array $input): TestResponse
    {
        return $this->graphQL('
            mutation($input: PipelineStageInput!) {
                createPipelineStage(input: $input) {
                    id
                    name
                    rotting_days
                    weight
                }
            }
        ', ['input' => $input]);
    }

    private function sendUpdateStage(array $stage, array $input): TestResponse
    {
        return $this->graphQL('
            mutation($id: ID!, $input: PipelineStageInput!) {
                updatePipelineStage(id: $id, input: $input) {
                    id
                    config
                }
            }
        ', [
            'id' => $stage['id'],
            'input' => [
                'pipeline_id' => $stage['pipeline_id'],
                'name' => $stage['name'],
                'rotting_days' => $stage['rotting_days'],
                'weight' => $stage['weight'],
                ...$input,
            ],
        ]);
    }

    private function savePipelineStage(array $stage, array $input): TestResponse
    {
        return $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    id
                }
            }
        ', [
            'id' => $stage['pipeline_id'],
            'input' => [
                'name' => Pipeline::find($stage['pipeline_id'])->name,
                'weight' => 0,
                'is_default' => false,
                'stages' => [
                    [
                        'stages_id' => $stage['id'],
                        'name' => $stage['name'],
                        'rotting_days' => 0,
                        'weight' => 1,
                        ...$input,
                    ],
                ],
            ],
        ]);
    }

    protected function createPipeline(): TestResponse
    {
        $name = fake()->name();

        $input = [
            'name' => $name,
            'weight' => 0,
            'is_default' => false,
            'stages' => [],
        ];

        return $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {       
                    id         
                    name
                }
            }
        ', [
                'input' => $input,
        ]);
    }

    public function testCreatePipelineWithStages()
    {
        $name = fake()->name();
        $stageName = fake()->name();
        $input = [
            'name' => $name,
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                [
                    'name' => $stageName,
                    'rotting_days' => 1,
                    'weight' => 1,
                ],
            ],
        ];

        $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {       
                    name,
                    stages {
                        name
                    }
                }
            }
        ', [
            'input' => $input,
        ])->assertJson([
            'data' => [
                'createPipeline' => [
                    'name' => $name,
                    'stages' => [
                        [
                            'name' => $stageName,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testUpdatePipelineWithStages()
    {
        $name = fake()->name();
        $stageName = fake()->name();
        $input = [
            'name' => $name,
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                [
                    'name' => $stageName,
                    'rotting_days' => 1,
                    'weight' => 1,
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {   
                    id,    
                    name,
                    stages {
                        id
                        name
                    }
                }
            }
        ', [
            'input' => $input,
        ]);
        $stageId = $response->json('data.createPipeline.stages.0.id');
        $pipelineId = $response->json('data.createPipeline.id');

        $stageNameNew = fake()->name();
        $input['stages'][0]['name'] = $stageNameNew;
        $input['stages'][0]['stages_id'] = $stageId;

        $this->graphQL('
        mutation($id: ID!, $input: PipelineInput!){
            updatePipeline(id: $id, input: $input){
                name,
                stages {
                    id
                    name
                }
            }
        }
        ', [
                    'id' => $pipelineId,
                    'input' => $input,
            ])->assertJson([
                'data' => [
                    'updatePipeline' => [
                        'name' => $name,
                        'stages' => [
                            [
                                'id' => $stageId,
                                'name' => $stageNameNew,
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function testUpdatePipelineStagesPreservesExistingStageIds()
    {
        $stageName1 = fake()->name();
        $stageName2 = fake()->name();
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => $stageName1, 'rotting_days' => 1, 'weight' => 1],
                ['name' => $stageName2, 'rotting_days' => 2, 'weight' => 2],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['input' => $input]);

        $pipelineId = $response->json('data.createPipeline.id');
        $stage1Id = $response->json('data.createPipeline.stages.0.id');
        $stage2Id = $response->json('data.createPipeline.stages.1.id');

        // Update: keep both stages with updated names, send stages_id to preserve them
        $updatedName1 = fake()->name();
        $updatedName2 = fake()->name();
        $input['stages'] = [
            ['stages_id' => $stage1Id, 'name' => $updatedName1, 'rotting_days' => 1, 'weight' => 1],
            ['stages_id' => $stage2Id, 'name' => $updatedName2, 'rotting_days' => 2, 'weight' => 2],
        ];

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['id' => $pipelineId, 'input' => $input])
        ->assertSuccessful();

        $updatedStages = $updateResponse->json('data.updatePipeline.stages');

        $this->assertCount(2, $updatedStages);
        $this->assertEquals($stage1Id, $updatedStages[0]['id'], 'Stage 1 ID must be preserved after update');
        $this->assertEquals($stage2Id, $updatedStages[1]['id'], 'Stage 2 ID must be preserved after update');
        $this->assertEquals($updatedName1, $updatedStages[0]['name']);
        $this->assertEquals($updatedName2, $updatedStages[1]['name']);
    }

    public function testUpdatePipelineRemovesOnlyDeletedStages()
    {
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => fake()->name(), 'rotting_days' => 1, 'weight' => 1],
                ['name' => fake()->name(), 'rotting_days' => 2, 'weight' => 2],
                ['name' => fake()->name(), 'rotting_days' => 3, 'weight' => 3],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['input' => $input]);

        $pipelineId = $response->json('data.createPipeline.id');
        $stage1Id = $response->json('data.createPipeline.stages.0.id');
        $stage2Id = $response->json('data.createPipeline.stages.1.id');

        // Update: keep stage 1, remove stages 2 and 3, add a new stage
        $newStageName = fake()->name();
        $input['stages'] = [
            ['stages_id' => $stage1Id, 'name' => $input['stages'][0]['name'], 'rotting_days' => 1, 'weight' => 1],
            ['name' => $newStageName, 'rotting_days' => 5, 'weight' => 4],
        ];

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['id' => $pipelineId, 'input' => $input])
        ->assertSuccessful();

        $stages = $updateResponse->json('data.updatePipeline.stages');

        // Should have exactly 2 stages
        $this->assertCount(2, $stages);

        // Stage 1 should keep its original ID
        $this->assertEquals($stage1Id, $stages[0]['id']);

        // Stage 2 (removed) should not exist
        $stageIds = array_column($stages, 'id');
        $this->assertNotContains($stage2Id, $stageIds);

        // New stage should exist
        $this->assertEquals($newStageName, $stages[1]['name']);
    }

    public function testCannotRemoveStageWithActiveLeads()
    {
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => fake()->name(), 'rotting_days' => 1, 'weight' => 1],
                ['name' => fake()->name(), 'rotting_days' => 2, 'weight' => 2],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['input' => $input]);

        $pipelineId = $response->json('data.createPipeline.id');
        $stage1Id = $response->json('data.createPipeline.stages.0.id');
        $stage2Id = $response->json('data.createPipeline.stages.1.id');

        $this->createLeadOnStage((int) $stage2Id);

        // Try to update pipeline removing stage 2 — should fail
        $input['stages'] = [
            ['stages_id' => $stage1Id, 'name' => $input['stages'][0]['name'], 'rotting_days' => 1, 'weight' => 1],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['id' => $pipelineId, 'input' => $input])
        ->assertJsonFragment([
            'message' => 'Cannot remove pipeline stages that have active leads or deals. Move them to another stage first.',
        ]);
    }

    public function testUpdatePipelineStagesPreservesIdsWithoutStagesId()
    {
        $stageName1 = 'StageAlpha-' . fake()->uuid();
        $stageName2 = 'StageBeta-' . fake()->uuid();
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => $stageName1, 'rotting_days' => 1, 'weight' => 1],
                ['name' => $stageName2, 'rotting_days' => 2, 'weight' => 2],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['input' => $input]);

        $pipelineId = $response->json('data.createPipeline.id');
        $stage1Id = $response->json('data.createPipeline.stages.0.id');
        $stage2Id = $response->json('data.createPipeline.stages.1.id');

        // Update WITHOUT stages_id — same as frontend behavior
        $input['stages'] = [
            ['name' => $stageName1, 'rotting_days' => 3, 'weight' => 1, 'pipeline_id' => $pipelineId],
            ['name' => $stageName2, 'rotting_days' => 4, 'weight' => 2, 'pipeline_id' => $pipelineId],
        ];

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    id
                    stages { id name rotting_days }
                }
            }
        ', ['id' => $pipelineId, 'input' => $input])
        ->assertSuccessful();

        $updatedStages = $updateResponse->json('data.updatePipeline.stages');

        $this->assertCount(2, $updatedStages);
        $this->assertEquals($stage1Id, $updatedStages[0]['id'], 'Stage 1 ID must be preserved even without stages_id');
        $this->assertEquals($stage2Id, $updatedStages[1]['id'], 'Stage 2 ID must be preserved even without stages_id');
        $this->assertEquals(3, $updatedStages[0]['rotting_days'], 'Stage 1 rotting_days must be updated');
        $this->assertEquals(4, $updatedStages[1]['rotting_days'], 'Stage 2 rotting_days must be updated');
    }

    public function testUpdatePipelineStagesPreservesIdsOnRename()
    {
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => 'Original-1', 'rotting_days' => 1, 'weight' => 1],
                ['name' => 'Original-2', 'rotting_days' => 2, 'weight' => 2],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['input' => $input]);

        $pipelineId = $response->json('data.createPipeline.id');
        $stage1Id = $response->json('data.createPipeline.stages.0.id');
        $stage2Id = $response->json('data.createPipeline.stages.1.id');

        // Rename both stages WITHOUT sending stages_id — frontend behavior
        $input['stages'] = [
            ['name' => 'Renamed-1', 'rotting_days' => 1, 'weight' => 1, 'pipeline_id' => $pipelineId],
            ['name' => 'Renamed-2', 'rotting_days' => 2, 'weight' => 2, 'pipeline_id' => $pipelineId],
        ];

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['id' => $pipelineId, 'input' => $input])
        ->assertSuccessful();

        $updatedStages = $updateResponse->json('data.updatePipeline.stages');

        $this->assertCount(2, $updatedStages);
        $this->assertEquals($stage1Id, $updatedStages[0]['id'], 'Stage 1 ID must be preserved after rename');
        $this->assertEquals($stage2Id, $updatedStages[1]['id'], 'Stage 2 ID must be preserved after rename');
        $this->assertEquals('Renamed-1', $updatedStages[0]['name']);
        $this->assertEquals('Renamed-2', $updatedStages[1]['name']);
    }

    public function testUpdatePipelineStagesWeightFollowsPosition()
    {
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => 'First', 'rotting_days' => 1, 'weight' => 0],
                ['name' => 'Second', 'rotting_days' => 1, 'weight' => 0],
                ['name' => 'Third', 'rotting_days' => 1, 'weight' => 0],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {
                    id
                    stages { id name weight }
                }
            }
        ', ['input' => $input]);

        $pipelineId = $response->json('data.createPipeline.id');

        // Reorder stages — frontend sends weight=0 for all (common pattern)
        $input['stages'] = [
            ['name' => 'Third', 'rotting_days' => 1, 'weight' => 0],
            ['name' => 'First', 'rotting_days' => 1, 'weight' => 0],
            ['name' => 'Second', 'rotting_days' => 1, 'weight' => 0],
        ];

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    id
                    stages { id name weight }
                }
            }
        ', ['id' => $pipelineId, 'input' => $input])
        ->assertSuccessful();

        $updatedStages = $updateResponse->json('data.updatePipeline.stages');

        $this->assertCount(3, $updatedStages);
        $this->assertEquals('Third', $updatedStages[0]['name']);
        $this->assertEquals('First', $updatedStages[1]['name']);
        $this->assertEquals('Second', $updatedStages[2]['name']);
    }

    public function testCreatePipeline()
    {
        $pipeline = $this->createPipeline();
        $data = $pipeline->json('data.createPipeline');
        $name = $data['name'];

        $pipeline->assertJson([
             'data' => [
                 'createPipeline' => [
                     'name' => $name,
                 ],
             ],
         ]);
    }

    public function testUpdatePipeline()
    {
        $pipeline = $this->createPipeline()->json('data.createPipeline');

        $newName = fake()->name();

        $input = [
             'name' => $newName,
             'weight' => 0,
             'is_default' => false,
             'stages' => [],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!){
                updatePipeline(id: $id, input: $input){
                    id,
                    name,
                    slug
                }
            }
            ', [
                'id' => $pipeline['id'],
                'input' => $input,
        ])->assertJson([
                'data' => [
                    'updatePipeline' => [
                        'name' => $newName,
                    ],
                ],
            ]);
    }

    public function testDeletePipeline()
    {
        $pipeline = $this->createPipeline()->json('data.createPipeline');

        $this->graphQL('
            mutation($id: ID!){
                deletePipeline(id: $id)
            }
            ', [
                'id' => $pipeline['id'],
        ])->assertJson([
                'data' => [
                    'deletePipeline' => true,
                ],
            ]);
    }

    public function testRestorePipeline()
    {
        $pipeline = $this->createPipeline()->json('data.createPipeline');

        $this->graphQL('
            mutation($id: ID!){
                deletePipeline(id: $id)
            }
            ', [
                'id' => $pipeline['id'],
        ])->assertJson([
                'data' => [
                    'deletePipeline' => true,
                ],
            ]);

        $this->graphQL('
            mutation($id: ID!){
                restorePipeline(id: $id)
            }
            ', [
                'id' => $pipeline['id'],
        ])->assertJson([
                'data' => [
                    'restorePipeline' => true,
                ],
            ]);
    }

    protected function createPipelineStage(): TestResponse
    {
        $pipeline = $this->createPipeline()->json('data.createPipeline');

        $name = fake()->name();

        $input = [
            'pipeline_id' => $pipeline['id'],
            'name' => $name,
            'weight' => 0,
            'rotting_days' => 0,
        ];

        return $this->graphQL('
            mutation($input: PipelineStageInput!){
                createPipelineStage(input: $input){
                    id,
                    name
                    pipeline{
                        id
                    }
                }
            }
            ', [
                'input' => $input,
            ]);
    }

    public function testCreatePipelineStage()
    {
        $stage = $this->createPipelineStage();
        $data = $stage->json('data.createPipelineStage');
        $name = $data['name'];

        $stage->assertJson([
                'data' => [
                    'createPipelineStage' => [
                        'name' => $name,
                    ],
                ],
            ]);

        $pipeline = $this->graphQL('
            query($id: Mixed!){
                pipelines(where: {column: ID, operator: EQ, value: $id}){
                    data{
                    id,
                    name,
                    stages{
                            id,
                            name
                        
                    }
                }
            }}
            ', [
                'id' => $data['pipeline']['id'],
            ])->assertSee($name);
    }

    public function testUpdatePipelineStage()
    {
        $stage = $this->createPipelineStage()->json('data.createPipelineStage');

        $newName = fake()->name();

        $input = [
            'pipeline_id' => $stage['pipeline']['id'],
            'name' => $newName,
            'weight' => 0,
            'rotting_days' => 0,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PipelineStageInput!){
                updatePipelineStage(id: $id, input: $input){
                    id,
                    name
                }
            }
            ', [
                'id' => $stage['id'],
                'input' => $input,
            ])->assertJson([
                'data' => [
                    'updatePipelineStage' => [
                        'name' => $newName,
                    ],
                ],
            ]);
    }

    public function testDeletePipelineStage()
    {
        $stage = $this->createPipelineStage()->json('data.createPipelineStage');

        $this->graphQL('
            mutation($id: ID!){
                deletePipelineStage(id: $id)
            }
            ', [
                'id' => $stage['id'],
            ])->assertJson([
                'data' => [
                    'deletePipelineStage' => true,
                ],
            ]);
    }

    public function testRestorePipelineStage()
    {
        $stage = $this->createPipelineStage()->json('data.createPipelineStage');

        $this->graphQL('
            mutation($id: ID!){
                deletePipelineStage(id: $id)
            }
            ', [
                'id' => $stage['id'],
            ])->assertJson([
                'data' => [
                    'deletePipelineStage' => true,
                ],
            ]);

        $this->graphQL('
            mutation($id: ID!){
                restorePipelineStage(id: $id)
            }
            ', [
                'id' => $stage['id'],
            ])->assertJson([
                'data' => [
                    'restorePipelineStage' => true,
                ],
            ]);
    }

    public function testPipelinesCanBeOrderedByIsDefault(): void
    {
        $defaultId = $this->createPipelineWithDefault('Pipeline Default ' . uniqid(), true);
        $regularId = $this->createPipelineWithDefault('Pipeline Regular ' . uniqid(), false);

        $this->assertOrdersByIsDefault(
            'query($ids: Mixed!, $order: SortOrder!) {
                pipelines(
                    where: { column: ID, operator: IN, value: $ids }
                    orderBy: [{ column: IS_DEFAULT, order: $order }]
                ) {
                    data { id is_default }
                }
            }',
            'data.pipelines.data',
            $defaultId,
            $regularId
        );
    }

    protected function createPipelineWithDefault(string $name, bool $isDefault): int
    {
        return (int) $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'name' => $name,
                'weight' => 0,
                'is_default' => $isDefault,
                'stages' => [],
            ],
        ])->assertGraphQLErrorFree()->json('data.createPipeline.id');
    }

    public function testRenameAndReorderWithoutStagesIdKeepsLeadsOnTheirStage(): void
    {
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => 'New', 'rotting_days' => 1, 'weight' => 0],
                ['name' => 'Contacted', 'rotting_days' => 1, 'weight' => 1],
            ],
        ];

        $response = $this->createPipelineFromInput($input);
        $pipelineId = $response->json('data.createPipeline.id');
        $newStageId = (int) $response->json('data.createPipeline.stages.0.id');
        $contactedStageId = (int) $response->json('data.createPipeline.stages.1.id');

        $leadInNew = $this->createLeadOnStage($newStageId);
        $leadInContacted = $this->createLeadOnStage($contactedStageId);

        $input['stages'] = [
            ['name' => 'Reached', 'rotting_days' => 1, 'weight' => 0, 'pipeline_id' => $pipelineId],
            ['name' => 'New', 'rotting_days' => 1, 'weight' => 1, 'pipeline_id' => $pipelineId],
        ];

        $updated = $this->updatePipelineFromInput($pipelineId, $input)->json('data.updatePipeline.stages');

        $this->assertSame(['Reached', 'New'], array_column($updated, 'name'));
        $this->assertSame($contactedStageId, (int) $updated[0]['id'], 'Contacted must keep its id after being renamed and reordered');
        $this->assertSame($newStageId, (int) $updated[1]['id']);
        $this->assertSame('New', Lead::find($leadInNew)->stage->name);
        $this->assertSame('Reached', Lead::find($leadInContacted)->stage->name);
    }

    public function testUpdatePipelineWithoutConfigKeepsStageConfig(): void
    {
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => 'Configured', 'rotting_days' => 1, 'weight' => 0, 'config' => ['follow_up' => ['enabled' => true]]],
            ],
        ];

        $response = $this->createPipelineFromInput($input);
        $pipelineId = $response->json('data.createPipeline.id');
        $stageId = (int) $response->json('data.createPipeline.stages.0.id');

        $input['name'] = fake()->name();
        $input['stages'] = [
            ['stages_id' => $stageId, 'name' => 'Configured', 'rotting_days' => 1, 'weight' => 0],
        ];

        $this->updatePipelineFromInput($pipelineId, $input);

        $this->assertSame(['follow_up' => ['enabled' => true]], PipelineStage::find($stageId)->config);
    }

    public function testCannotRemoveStageWithActiveDeals(): void
    {
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => 'Open', 'rotting_days' => 1, 'weight' => 0],
                ['name' => 'Negotiating', 'rotting_days' => 1, 'weight' => 1],
            ],
        ];

        $response = $this->createPipelineFromInput($input);
        $pipelineId = $response->json('data.createPipeline.id');
        $openStageId = (int) $response->json('data.createPipeline.stages.0.id');
        $negotiatingStageId = (int) $response->json('data.createPipeline.stages.1.id');

        $this->graphQL('
            mutation($input: DealInput!) {
                createDeal(input: $input) { id }
            }
        ', ['input' => [
            'title' => 'Deal ' . fake()->word(),
            'pipeline_id' => $pipelineId,
            'pipeline_stage_id' => $negotiatingStageId,
        ]])->assertSuccessful();

        $input['stages'] = [
            ['stages_id' => $openStageId, 'name' => 'Open', 'rotting_days' => 1, 'weight' => 0],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) { id }
            }
        ', ['id' => $pipelineId, 'input' => $input])->assertJsonFragment([
            'message' => 'Cannot remove pipeline stages that have active leads or deals. Move them to another stage first.',
        ]);

        $this->assertSame(0, (int) Deal::where('pipeline_stage_id', $negotiatingStageId)->first()->is_deleted);
    }

    public function testRemovedStageIsSoftDeletedAndRestoredLeadKeepsIt(): void
    {
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => 'Keep', 'rotting_days' => 1, 'weight' => 0],
                ['name' => 'Drop', 'rotting_days' => 1, 'weight' => 1],
            ],
        ];

        $response = $this->createPipelineFromInput($input);
        $pipelineId = $response->json('data.createPipeline.id');
        $keepStageId = (int) $response->json('data.createPipeline.stages.0.id');
        $dropStageId = (int) $response->json('data.createPipeline.stages.1.id');

        $leadId = $this->createLeadOnStage($dropStageId);
        Lead::find($leadId)->softDelete();

        $input['stages'] = [
            ['stages_id' => $keepStageId, 'name' => 'Keep', 'rotting_days' => 1, 'weight' => 0],
        ];

        $updated = $this->updatePipelineFromInput($pipelineId, $input)->json('data.updatePipeline.stages');

        $this->assertSame([$keepStageId], array_map('intval', array_column($updated, 'id')));

        $dropped = PipelineStage::find($dropStageId);
        $this->assertNotNull($dropped, 'Removed stage must survive as a soft-deleted row');
        $this->assertSame(1, (int) $dropped->is_deleted);

        $lead = Lead::find($leadId);
        $lead->restoreRecord();
        $this->assertSame('Drop', $lead->fresh()->stage->name);

        $input['stages'][] = ['name' => 'Drop', 'rotting_days' => 1, 'weight' => 1];
        $readded = $this->updatePipelineFromInput($pipelineId, $input)->json('data.updatePipeline.stages');

        $this->assertSame($dropStageId, (int) $readded[1]['id'], 'Re-adding the name restores the soft-deleted stage');
    }

    public function testSingleSaveUpdatesAddsAndRemovesStages(): void
    {
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => 'New', 'rotting_days' => 1, 'weight' => 0, 'config' => ['follow_up' => ['enabled' => true]]],
                ['name' => 'Contacted', 'rotting_days' => 2, 'weight' => 1],
                ['name' => 'Lost', 'rotting_days' => 3, 'weight' => 2],
            ],
        ];

        $response = $this->createPipelineFromInput($input);
        $pipelineId = $response->json('data.createPipeline.id');
        [$newId, $contactedId, $lostId] = array_map('intval', array_column($response->json('data.createPipeline.stages'), 'id'));

        $leadId = $this->createLeadOnStage($newId);

        $input['name'] = 'Renamed pipeline';
        $input['stages'] = [
            ['stages_id' => $newId, 'name' => 'Fresh', 'rotting_days' => 7, 'weight' => 0, 'config' => ['follow_up' => ['enabled' => false]]],
            ['stages_id' => $contactedId, 'name' => 'Contacted', 'rotting_days' => 2, 'weight' => 1],
            ['name' => 'Won', 'rotting_days' => 0, 'weight' => 2],
        ];

        $updated = $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    name
                    stage_count
                    stages { id name rotting_days weight config }
                }
            }
        ', ['id' => $pipelineId, 'input' => $input])->assertGraphQLErrorFree()->json('data.updatePipeline');

        $this->assertSame('Renamed pipeline', $updated['name']);
        $this->assertSame(3, $updated['stage_count']);
        $this->assertSame(['Fresh', 'Contacted', 'Won'], array_column($updated['stages'], 'name'));
        $this->assertSame([$newId, $contactedId], array_map('intval', array_column(array_slice($updated['stages'], 0, 2), 'id')));
        $this->assertNotContains($lostId, array_map('intval', array_column($updated['stages'], 'id')));

        $fresh = $updated['stages'][0];
        $this->assertSame(7, $fresh['rotting_days']);
        $this->assertSame(['follow_up' => ['enabled' => false]], $fresh['config']);

        $won = $updated['stages'][2];
        $this->assertSame(2, $won['weight']);
        $this->assertSame(0, $won['rotting_days']);

        $lead = Lead::find($leadId);
        $this->assertSame($newId, (int) $lead->pipeline_stage_id);
        $this->assertSame('Fresh', $lead->stage->name);

        $this->assertSame(1, (int) PipelineStage::find($lostId)->is_deleted);
    }

    protected function createPipelineFromInput(array $input): TestResponse
    {
        return $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['input' => $input])->assertGraphQLErrorFree();
    }

    protected function updatePipelineFromInput(string|int $pipelineId, array $input): TestResponse
    {
        return $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['id' => $pipelineId, 'input' => $input])->assertGraphQLErrorFree();
    }

    protected function createLeadOnStage(int $stageId): int
    {
        $branch = auth()->user()->getCurrentBranch();

        return (int) $this->graphQL('
            mutation($input: LeadInput!) {
                createLead(input: $input) { id }
            }
        ', [
            'input' => [
                'branch_id' => $branch->getId(),
                'title' => fake()->title(),
                'pipeline_stage_id' => $stageId,
                'people' => [
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'contacts' => [
                        ['value' => fake()->email(), 'contacts_types_id' => 1, 'weight' => 0],
                    ],
                ],
            ],
        ])->assertGraphQLErrorFree()->json('data.createLead.id');
    }
}
