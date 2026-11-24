<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Classification\Actions\ApplyPeopleClassificationAction;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationFieldEnum;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationSourceEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\Actions\WireRuleAction;
use Kanvas\Workflow\Rules\DynamicRuleWorkflow;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Classification is bookkeeping, not an edit: its custom-field writes must not reach rules listening
 * on CREATE_CUSTOM_FIELD (the SIPGO sync request rule), while PEOPLE_CLASSIFIED itself still fires.
 */
#[Group('serial')]
final class PeopleClassificationWorkflowSilenceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'workflow'];

    public function test_applying_a_classification_fires_people_classified_but_no_custom_field_events(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        Action::factory()->create([
            'name' => 'Generate Company Dashboard',
            'kind' => ActionKindEnum::WORKFLOW->value,
        ]);
        $activity = Action::query()->firstOrFail()->model_name;

        foreach ([WorkflowEnum::CREATE_CUSTOM_FIELD, WorkflowEnum::PEOPLE_CLASSIFIED] as $event) {
            $this->assertNotNull(new WireRuleAction(
                $app,
                $company->getId(),
                People::class,
                $event->value,
                $activity,
                'Classification silence test (' . $event->value . ')',
                'test',
            )->execute());
        }

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Ana', 'lastname' => 'Pérez']);

        $before = StoredWorkflow::query()->where('class', DynamicRuleWorkflow::class)->count();

        new ApplyPeopleClassificationAction(
            people: $people,
            level: 'Gerencia',
            area: 'Ventas',
            source: PeopleClassificationSourceEnum::AI,
        )->execute();

        $this->assertSame($before + 1, StoredWorkflow::query()->where('class', DynamicRuleWorkflow::class)->count());
        $this->assertSame('Gerencia', $people->refresh()->get(PeopleClassificationFieldEnum::LEVEL->value));
    }
}
