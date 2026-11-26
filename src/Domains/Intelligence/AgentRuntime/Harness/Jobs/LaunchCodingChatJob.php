<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\CloseCodingChatAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\PostCodingChatMessageAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\RestartFactoryServerAction;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPrompt;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingChatSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\LaunchStageEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\CheckoutInUseException;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\CheckoutHolderNotice;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\HeldChatMessages;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Opens the factory checkout for a coding chat and sends the first instruction. The factory writes the
 * persona, rules and baseline into the checkout itself, so the prompt is the person's words alone.
 */
class LaunchCodingChatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    private const int TURN_CHECK_SECONDS = 60;

    /** What the factory answers when the branch a task run needs is checked out in another worktree. */
    private const string BRANCH_TAKEN = 'could not create a worktree';

    /**
     * A launch can reach the worker before the row that dispatched it is visible (a transaction still
     * open on the dispatching side), so a missing row is looked for again before the launch gives up.
     */
    private const int MISSING_ROW_LOOKUPS = 5;

    private const int MISSING_ROW_DELAY_SECONDS = 5;

    /** A provisioned run is polled every minute; one unheard-of for this long is not holding the devices. */
    private const int RUNNING_SILENT_MINUTES = 30;

    public int $tries = 1;

    public function __construct(
        public readonly Apps $app,
        public readonly int $sessionId,
        public readonly string $instruction,
        public readonly bool $retriedAfterRelease = false,
        public readonly int $lookups = 0,
    ) {
        $this->onQueue('coding');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $session = AgentTaskSession::findInApp($this->sessionId, $this->app);

        if ($session === null) {
            $this->lookAgainLater();

            return;
        }

        if (! $session->isLive()) {
            return;
        }

        $agent = $session->agent;
        $machine = $session->machine;

        if ($agent === null || $machine === null || $session->repo_slug === null) {
            new CloseCodingChatAction(
                $session,
                'The chat lost its agent, machine or repository before it could start.',
                HarnessStatusEnum::FAILED
            )->execute();

            return;
        }

        if ($session->isTaskRun() && $this->waitsForItsTurn($session, $agent)) {
            return;
        }

        try {
            $factory = HarnessFactory::codingFactoryFor($session);
            $opened = $factory->open(
                repo: $session->repo_slug,
                owner: $session->uuid,
                name: $agent->name,
                persona: CodingChatSettingEnum::personaFor($agent),
                worktreeBranch: $session->taskRunBranch(),
            );

            // `open` runs the baseline, for minutes. A chat ended or switched meanwhile was closed under
            // this job; writing to the row it holds would bring it back live on a checkout already released.
            if (! $session->fresh()?->isLive()) {
                $factory->release($session->repo_slug, $session->uuid);

                return;
            }

            $session->port = $opened['port'];
            $session->server_password = $opened['password'];
            $session->endpoint = 'http://127.0.0.1:' . $opened['port'];
            $session->session_data_path = $opened['directory'];
            $session->branch = $opened['branch'];
            $session->provider = $opened['provider'];
            $session->model = $opened['model'];
            WatchCodingChatJob::dispatch($this->app, $session->getId());

            $instruction = trim($this->instruction);

            if ($instruction === '') {
                // Opened by `/repo <App>` alone: the opencode session is created by the first message. With
                // nothing held the row goes idle and that message starts it; with something held the row
                // stays starting, so a message arriving now is held too instead of starting a second session.
                $instruction = HeldChatMessages::handOver(
                    $session,
                    static function () use ($session): string {
                        $held = implode("\n\n", HeldChatMessages::take($session));

                        if ($held === '') {
                            $session->status = HarnessStatusEnum::IDLE->value;
                            $session->markLaunchStage(null);
                        }

                        return $held;
                    }
                );

                if ($instruction === '') {
                    new PostCodingChatMessageAction(
                        $session,
                        '📂 Ready in **' . $session->repo_slug . '** on branch `' . $session->branch . '`. What should I do?'
                    )->execute();

                    return;
                }
            }

            $session->markLaunchStage(LaunchStageEnum::PROMPTING);

            new PostCodingChatMessageAction(
                $session,
                '📂 Working in **' . $session->repo_slug . '** on branch `' . $session->branch . '`. '
                . 'I will post here when this turn is done.'
            )->execute();

            $harness = HarnessFactory::forSession($session);
            RestartFactoryServerAction::retrying(
                $session,
                static fn () => $harness->start($session, new HarnessPrompt(task: $instruction, policy: ''))
            );

            foreach (HeldChatMessages::handOver($session, static fn (): array => HeldChatMessages::take($session)) as $held) {
                $harness->steer($session, $held);
            }
        } catch (CheckoutInUseException $e) {
            $this->refuseHeldCheckout($session, $e);

            return;
        } catch (Throwable $e) {
            if (! $this->retriedAfterRelease && $this->releasedFinishedRunsOnItsBranch($session, $e)) {
                self::dispatch(
                    $this->app,
                    $this->sessionId,
                    $this->instruction,
                    retriedAfterRelease: true,
                );

                return;
            }

            // An unreachable or refusing Mac is said in the chat; anything else is a fault worth tracking.
            if (! $e instanceof HarnessTransportException) {
                report($e);
            }

            new CloseCodingChatAction($session, 'Could not start: ' . $e->getMessage(), HarnessStatusEnum::FAILED)->execute();

            return;
        }

        $session->markLaunchStage(null);
        PollHarnessSessionJob::dispatch($this->app, $session->getId());
    }

    /**
     * Workflow task runs take turns on the Mac, first come first served: each has its own worktree, but
     * the simulator, the emulator and Metro's port are one per machine. A run whose turn has not come
     * stays queued and asks again in a minute — waiting is never a failure, so the task never blocks for it.
     */
    private function waitsForItsTurn(AgentTaskSession $session, Agent $agent): bool
    {
        $others = AgentTaskSession::query()
            ->where('agent_machine_id', $session->agent_machine_id)
            ->where('id', '!=', $session->getId())
            ->whereNotNull('chat_session_id')
            ->notDeleted()
            ->live()
            ->with('task')
            ->get()
            ->filter(static fn (AgentTaskSession $other): bool => $other->isTaskRun() && self::isAlive($other));

        $running = $others->filter(static fn (AgentTaskSession $other): bool => $other->isProvisioned())->count();
        $aheadInLine = $others->contains(
            static fn (AgentTaskSession $other): bool => ! $other->isProvisioned() && $other->getId() < $session->getId()
        );

        if ($running < CodingChatSettingEnum::maxTaskRunsFor($agent) && ! $aheadInLine) {
            if ($session->launchStage() === LaunchStageEnum::WAITING_FOR_FACTORY) {
                $session->markLaunchStage(LaunchStageEnum::PREPARING_WORKTREE);
            }

            return false;
        }

        $session->markLaunchStage(LaunchStageEnum::WAITING_FOR_FACTORY);
        self::dispatch($this->app, $this->sessionId, $this->instruction)
            ->delay(Carbon::now()->addSeconds(self::TURN_CHECK_SECONDS));

        return true;
    }

    /**
     * Only a run that is still being heard from holds the Mac. A row whose launch or poller died stays
     * live until the sweeper closes it, and a waiting run touches its own heartbeat every minute so it is
     * never swept: counting a dead row would keep every later run on the machine waiting for good.
     */
    private static function isAlive(AgentTaskSession $other): bool
    {
        return $other->heardFromWithin(
            $other->isProvisioned() ? self::RUNNING_SILENT_MINUTES : $other->launchStaleMinutes()
        );
    }

    private function lookAgainLater(): void
    {
        if ($this->lookups >= self::MISSING_ROW_LOOKUPS) {
            Log::warning('Coding chat launch found no session row; giving up', [
                'session_id' => $this->sessionId,
                'app_id' => $this->app->getId(),
                'lookups' => $this->lookups,
            ]);

            return;
        }

        self::dispatch(
            $this->app,
            $this->sessionId,
            $this->instruction,
            $this->retriedAfterRelease,
            $this->lookups + 1,
        )->delay(Carbon::now()->addSeconds(self::MISSING_ROW_DELAY_SECONDS));
    }

    /**
     * A launch that dies outside its own catch would leave the session STARTING, and the chat answering
     * "still opening" to every message until the sweeper reaps it ten minutes later.
     */
    public function failed(Throwable $e): void
    {
        try {
            $this->overwriteAppService($this->app);

            $session = AgentTaskSession::findInApp($this->sessionId, $this->app);

            if ($session !== null && $session->isLive()) {
                new CloseCodingChatAction($session, 'Could not start: ' . $e->getMessage(), HarnessStatusEnum::FAILED)->execute();
            }
        } catch (Throwable $closing) {
            report($closing);
        }
    }

    /**
     * The steps of one ticket share a branch, and a finished step's worktree that outlived its run still
     * holds it, so the next step cannot check the branch out. Releasing the finished runs on that branch
     * frees it without losing anything: a release commits what a run left uncommitted on the branch.
     */
    private function releasedFinishedRunsOnItsBranch(AgentTaskSession $session, Throwable $e): bool
    {
        $branch = $session->taskRunBranch();

        if ($branch === null || ! str_contains($e->getMessage(), self::BRANCH_TAKEN)) {
            return false;
        }

        $finished = AgentTaskSession::query()
            ->fromApp($this->app)
            ->where('agent_machine_id', $session->agent_machine_id)
            ->where('repo_slug', $session->repo_slug)
            ->where('branch', $branch)
            ->whereIn('status', HarnessStatusEnum::terminalValues())
            ->whereKeyNot($session->getId())
            ->get();

        $factory = HarnessFactory::codingFactoryFor($session);
        $released = false;

        foreach ($finished as $run) {
            try {
                $factory?->release((string) $run->repo_slug, $run->uuid);
                $released = true;
            } catch (Throwable) {
            }
        }

        return $released;
    }

    /**
     * Said in place of the generic "could not start": the notice already says what to do, and the generic
     * one's "start again with /repo" would only be refused the same way. A task run carries it as its
     * reason instead, which is what the task that started it is blocked with.
     */
    private function refuseHeldCheckout(AgentTaskSession $session, CheckoutInUseException $e): void
    {
        $notice = new CheckoutHolderNotice($session, $e->holder)->message();

        new CloseCodingChatAction(
            session: $session,
            reason: $notice,
            status: HarnessStatusEnum::FAILED,
            notify: false,
        )->execute();

        if (! $session->isTaskRun()) {
            new PostCodingChatMessageAction($session, $notice)->execute();
        }
    }
}
