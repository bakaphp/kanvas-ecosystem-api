<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TruncatesTitleTraitTest extends TestCase
{
    /**
     * @return iterable<string, array{0: class-string<Plan|Task>}>
     */
    public static function modelProvider(): iterable
    {
        yield 'plan' => [Plan::class];
        yield 'task' => [Task::class];
    }

    /**
     * @param class-string<Plan|Task> $model
     */
    #[DataProvider('modelProvider')]
    public function testOverlongTitleIsTruncatedToColumnLimit(string $model): void
    {
        $entity = new $model();
        $entity->title = str_repeat('a', 500);

        $this->assertLessThanOrEqual(255, mb_strlen($entity->title));
        $this->assertStringEndsWith('…', $entity->title);
    }

    /**
     * @param class-string<Plan|Task> $model
     */
    #[DataProvider('modelProvider')]
    public function testTitleAtOrUnderLimitIsUntouched(string $model): void
    {
        $entity = new $model();

        $entity->title = 'Short title';
        $this->assertSame('Short title', $entity->title);

        $exact = str_repeat('b', 255);
        $entity->title = $exact;
        $this->assertSame($exact, $entity->title);
    }

    public function testMultibyteTitleTruncatesByCharactersNotBytes(): void
    {
        $entity = new Plan();
        $entity->title = str_repeat('é', 500);

        $this->assertLessThanOrEqual(255, mb_strlen($entity->title));
        $this->assertStringEndsWith('…', $entity->title);
    }

    public function testOverlongTitlePersistsThroughCreatePlan(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: str_repeat('a', 500),
                planType: 'task',
                user: $user,
            ),
            tasks: [
                new TaskData(plan: null, title: str_repeat('b', 400), sequence: 1),
            ],
        )->execute();

        $this->assertLessThanOrEqual(255, mb_strlen($plan->title));
        $this->assertLessThanOrEqual(255, mb_strlen($plan->tasks()->firstOrFail()->title));
    }
}
