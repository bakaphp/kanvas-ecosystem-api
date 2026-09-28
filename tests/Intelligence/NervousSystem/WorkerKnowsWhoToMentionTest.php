<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Support\MentionHandle;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeWorkerForPlanJob;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A worker that finishes and posts a comment is talking to an empty room.
 *
 * Its own wake prompt never named its PM, so it had nobody to address — and a plain comment wakes no
 * one, which is how "PM asks, worker answers, PM never hears it" happens. The @mention is the only
 * route a worker can take back to its PM in the same turn, so the prompt has to carry the handle.
 */
final class WorkerKnowsWhoToMentionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = ['mysql', 'intelligence', 'social'];

    public function testTheWakePromptNamesThePmAndItsHandle(): void
    {
        $pm = $this->makeAgent();
        $handle = $this->giveHandle($pm->user);
        $plan = $this->planManagedBy($pm);

        $message = $this->wakeMessage($plan);

        $this->assertStringContainsString('@' . $handle, $message);
        $this->assertStringContainsString($pm->name, $message);
        $this->assertStringContainsString('A COMMENT IS A NOTE', $message);
    }

    /**
     * A display name with a space tokenises to its first word and resolves to nobody, so telling the
     * worker to write `@Liliana Garcia` produces a mention that silently reaches no one.
     */
    public function testAnUnmentionablePmIsNamedRatherThanAtSigned(): void
    {
        $pm = $this->makeAgent();
        $this->giveHandle($pm->user, 'Liliana Garcia');
        $plan = $this->planManagedBy($pm);

        $message = $this->wakeMessage($plan);

        $this->assertStringContainsString('cannot be @mentioned', $message);
        $this->assertStringNotContainsString('@Liliana', $message);
    }

    /** No project means no PM, but the human who asked for the work is still waiting on it. */
    public function testAPlanWithNoProjectStillPointsAtTheHumanWhoAskedForIt(): void
    {
        $handle = $this->giveHandle(static::$cachedUser);

        $message = $this->wakeMessage($this->makePlan());

        $this->assertStringContainsString('The person who asked for this work is @' . $handle, $message);
        $this->assertStringNotContainsString('Your project manager', $message);
    }

    /** Nobody reachable means no instruction to reach them — not an @ that lands nowhere. */
    public function testNobodyMentionableMeansNoBlockAtAll(): void
    {
        $this->giveHandle(static::$cachedUser, 'Spaced Out Name');

        $message = $this->wakeMessage($this->makePlan());

        $this->assertStringNotContainsString('WHO IS WAITING ON YOU', $message);
    }

    public function testAHandleWithASpaceIsNotConsideredMentionable(): void
    {
        $user = $this->makeAgent()->user;
        $this->giveHandle($user, 'Two Words');

        $this->assertNull(MentionHandle::forUser($user, $this->app()));
    }

    public function testMentionMatchingIsByTokenNotSubstring(): void
    {
        $user = $this->makeAgent()->user;
        $handle = $this->giveHandle($user);

        $this->assertTrue(MentionHandle::isNamedIn('hey @' . $handle . ', done', $user, $this->app()));
        $this->assertFalse(
            MentionHandle::isNamedIn('mailed ' . $handle . '@example.com', $user, $this->app()),
            'A bare occurrence of the name is not a mention.'
        );
    }

    private function planManagedBy(Agent $pm): Plan
    {
        $project = new CreateProjectAction(ProjectData::from(
            $this->app(),
            static::$cachedUser,
            static::$cachedUser->getCurrentCompany(),
            [
                'title' => 'Mentionable ' . fake()->unique()->lexify('?????'),
                'agent_id' => $pm->getId(),
            ],
        ))->execute();

        $plan = $this->makePlan();
        $plan->project_id = $project->getId();
        $plan->saveQuietly();

        return $plan->refresh();
    }

    private function wakeMessage(Plan $plan): string
    {
        return new ReflectionMethod(WakeWorkerForPlanJob::class, 'buildMessage')
            ->invoke(new WakeWorkerForPlanJob($plan));
    }

    private function giveHandle(Users $user, ?string $handle = null): string
    {
        $handle ??= 'handle' . fake()->unique()->lexify('?????');

        UsersAssociatedApps::updateOrCreate(
            [
                'users_id' => $user->getId(),
                'apps_id' => $this->app()->getId(),
                'companies_id' => 0,
            ],
            [
                'identify_id' => (string) $user->getId(),
                'displayname' => $handle,
                'password' => $user->password,
                'email' => $user->email,
                'user_active' => 1,
                'status' => 1,
            ],
        );

        return $handle;
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }
}
