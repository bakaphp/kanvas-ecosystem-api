<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        try {
            $opened = new FactoryServeClient($machine, CodingChatSettingEnum::factoryDirFor($agent))->open(
                repo: $session->repo_slug,
                owner: $session->uuid,
                name: $agent->name,
                persona: CodingChatSettingEnum::personaFor($agent),
            );

            $session->port = $opened['port'];
            $session->server_password = $opened['password'];
            $session->endpoint = 'http://127.0.0.1:' . $opened['port'];
            $session->session_data_path = $opened['directory'];
            $session->branch = $opened['branch'];
            $session->provider = $opened['provider'];
            $session->model = $opened['model'];
            WatchCodingChatJob::dispatch($this->app, $session->getId());

            if (trim($this->instruction) === '') {
                // Opened by `/repo <App>` alone: the opencode session is created by the first message.
                $session->status = HarnessStatusEnum::IDLE->value;
                $session->markLaunchStage(null);

                new PostCodingChatMessageAction(
                    $session,
                    '📂 Ready in **' . $session->repo_slug . '** on branch `' . $session->branch . '`. What should I do?'
                )->execute();

                return;
            }

            $session->markLaunchStage(LaunchStageEnum::PROMPTING);

            new PostCodingChatMessageAction(
                $session,
                '📂 Working in **' . $session->repo_slug . '** on branch `' . $session->branch . '`. '
                . 'I will post here when this turn is done.'
            )->execute();

            HarnessFactory::forSession($session)->start($session, new HarnessPrompt(task: $this->instruction, policy: ''));
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
