<?php

declare(strict_types=1);

namespace Tests\GraphQL\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Scheduling\Actions\CreateScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionSweepService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ScheduledActionMutationTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private function makeAction(?string $cron = null, ?Carbon $runAt = null): ScheduledAction
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        /** @var Companies $company */
        $company = $user->getCurrentCompany();

        return new CreateScheduledActionAction(
            new ScheduledActionData(
                app: $app,
                company: $company,
                user: $user,
                type: ScheduledActionTypeEnum::REMINDER,
                timezone: 'UTC',
                runAt: $runAt ?? Carbon::now()->addDay(),
                message: 'Ping the client',
                recurrenceCron: $cron,
            ),
        )->execute();
    }

    public function testQueryListsScheduledActionsForCurrentCompany(): void
    {
        $action = $this->makeAction(cron: '0 9 * * *');

        $response = $this->graphQL('
            query {
                nervousSystemScheduledActions(
                    where: { column: UUID, operator: EQ, value: "' . $action->uuid . '" }
                ) {
                    data {
                        id
                        uuid
                        action_type
                        status
                        timezone
                        is_recurring
                        recurrence_cron
                        payload
                        recipient { id }
                    }
                    paginatorInfo { total }
                }
            }
        ')->assertSuccessful();

        $row = $response->json('data.nervousSystemScheduledActions.data.0');

        $this->assertSame(1, $response->json('data.nervousSystemScheduledActions.paginatorInfo.total'));
        $this->assertSame($action->uuid, $row['uuid']);
        $this->assertSame(ScheduledActionTypeEnum::REMINDER->value, $row['action_type']);
        $this->assertSame(ScheduledActionStatusEnum::PENDING->value, $row['status']);
        $this->assertTrue($row['is_recurring']);
        $this->assertSame('0 9 * * *', $row['recurrence_cron']);
        $this->assertSame('Ping the client', $row['payload']['message']);
        $this->assertSame((string) auth()->user()->getId(), (string) $row['recipient']['id']);
    }

    public function testPauseKeepsRowOutOfTheSweep(): void
    {
        $action = $this->makeAction();

        $response = $this->graphQL('
            mutation {
                pauseNervousSystemScheduledAction(id: ' . $action->getId() . ') {
                    id
                    status
                }
            }
        ')->assertSuccessful();

        $this->assertSame(
            ScheduledActionStatusEnum::PAUSED->value,
            $response->json('data.pauseNervousSystemScheduledAction.status'),
        );

        // Make it due; a paused row must still never be claimed.
        $action->refresh();
        $action->run_at = Carbon::now()->subMinute();
        $action->saveOrFail();

        $claimed = new ScheduledActionSweepService()->claimDue();

        $this->assertNotContains($action->getId(), $claimed->pluck('id')->all());
        $this->assertSame(ScheduledActionStatusEnum::PAUSED->value, $action->refresh()->status);
    }

    public function testResumeRearmsARecurringRowThatSatPausedPastItsSlot(): void
    {
        $action = $this->makeAction(cron: '*/30 * * * *');

        $this->graphQL('
            mutation {
                pauseNervousSystemScheduledAction(id: ' . $action->getId() . ') { id }
            }
        ')->assertSuccessful();

        $action->refresh();
        $action->run_at = Carbon::now()->subHours(3);
        $action->saveOrFail();

        $response = $this->graphQL('
            mutation {
                resumeNervousSystemScheduledAction(id: ' . $action->getId() . ') {
                    id
                    status
                }
            }
        ')->assertSuccessful();

        $this->assertSame(
            ScheduledActionStatusEnum::PENDING->value,
            $response->json('data.resumeNervousSystemScheduledAction.status'),
        );
        $this->assertTrue($action->refresh()->run_at->greaterThan(Carbon::now()));
    }

    public function testResumeLeavesAOneOffRunAtAloneSoItFiresLate(): void
    {
        $action = $this->makeAction();

        $this->graphQL('
            mutation {
                pauseNervousSystemScheduledAction(id: ' . $action->getId() . ') { id }
            }
        ')->assertSuccessful();

        $action->refresh();
        $pastRunAt = Carbon::now()->subHours(2);
        $action->run_at = $pastRunAt;
        $action->saveOrFail();

        $this->graphQL('
            mutation {
                resumeNervousSystemScheduledAction(id: ' . $action->getId() . ') { id }
            }
        ')->assertSuccessful();

        $action->refresh();

        $this->assertSame(ScheduledActionStatusEnum::PENDING->value, $action->status);
        $this->assertSame($pastRunAt->toDateTimeString(), $action->run_at->toDateTimeString());
    }

    public function testCancelIsTerminalAndBlocksAFollowUpPause(): void
    {
        $action = $this->makeAction();

        $response = $this->graphQL('
            mutation {
                cancelNervousSystemScheduledAction(id: ' . $action->getId() . ') {
                    id
                    status
                }
            }
        ')->assertSuccessful();

        $this->assertSame(
            ScheduledActionStatusEnum::CANCELLED->value,
            $response->json('data.cancelNervousSystemScheduledAction.status'),
        );

        $this->graphQL('
            mutation {
                pauseNervousSystemScheduledAction(id: ' . $action->getId() . ') { id }
            }
        ')->assertGraphQLErrorMessage("A scheduled action in status 'cancelled' cannot be paused.");
    }
}
