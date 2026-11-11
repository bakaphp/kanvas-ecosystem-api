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
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Services\FactoryServeClient;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\CloseCodingChatAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\PostCodingChatMessageAction;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPrompt;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingChatSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\LaunchStageEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\CheckoutInUseException;
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

    public int $tries = 1;

    public function __construct(
        public readonly Apps $app,
        public readonly int $sessionId,
        public readonly string $instruction,
    ) {
        $this->onQueue('coding');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->where('id', $this->sessionId)->fromApp($this->app)->first();

        if ($session === null || ! $session->isLive()) {
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
            $opened = new FactoryServeClient($machine, CodingChatSettingEnum::factoryDirFor($agent))->open(
                repo: $session->repo_slug,
                owner: $session->uuid,
                name: $agent->name,
                persona: CodingChatSettingEnum::personaFor($agent),
                worktreeBranch: $session->taskRunBranch(),
            );

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
                // Opened by `/repo <App>` alone: the opencode session is created by the first message.
                $session->status = HarnessStatusEnum::IDLE->value;
                $session->markLaunchStage(null);

                // Taken only once the row says IDLE: a message arriving after this sees the chat open and
                // goes to the agent directly, so nothing falls between the two.
                $instruction = implode("\n\n", HeldChatMessages::take($session));

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
            $harness->start($session, new HarnessPrompt(task: $instruction, policy: ''));

            foreach (HeldChatMessages::take($session) as $held) {
                $harness->steer($session, $held);
            }
        } catch (CheckoutInUseException $e) {
            $this->refuseHeldCheckout($session, $e);

            return;
        } catch (Throwable $e) {
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
            ->get()
            ->filter(static fn (AgentTaskSession $other): bool => $other->isTaskRun());

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
     * A launch that dies outside its own catch would leave the session STARTING, and the chat answering
     * "still opening" to every message until the sweeper reaps it ten minutes later.
     */
    public function failed(Throwable $e): void
    {
        try {
            $this->overwriteAppService($this->app);

            /** @var AgentTaskSession|null $session */
            $session = AgentTaskSession::query()->where('id', $this->sessionId)->fromApp($this->app)->first();

            if ($session !== null && $session->isLive()) {
                new CloseCodingChatAction($session, 'Could not start: ' . $e->getMessage(), HarnessStatusEnum::FAILED)->execute();
            }
        } catch (Throwable $closing) {
            report($closing);
        }
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
