<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\CloseCodingChatAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\OpenCodingChatAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\PostCodingChatMessageAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\AgentHarness;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\HarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPrompt;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingChatSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\PermissionDecisionEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\PollHarnessSessionJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\CodingChatCheckouts;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\HeldChatMessages;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * The chat as a remote terminal for the opencode factory: no LLM of Kanvas's own reads the message.
 * Each message is the next prompt to one opencode session in the factory checkout, the way typing into
 * `akira` is; the turn runs in the background and its answer is posted to this conversation when it
 * closes. What this returns is only the immediate acknowledgement.
 */
class RunCodingChatAction
{
    public const string HELP = "This chat drives the coding agent on the factory Mac.\n\n"
        . "- `/repo <App> [what to do]` — work in that checkout instead, e.g. `/repo Quorum-app fix the login spinner`\n"
        . "- `/take <App> [what to do]` — move that checkout here from another conversation of yours\n"
        . "- `/status` — where it stands\n"
        . "- `/stop` — stop the current turn\n"
        . "- `/end` — close the chat and free the checkout\n\n"
        . 'Anything else goes straight to the coding agent.';

    private const string REPO_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    private const string SWITCH_REQUEST = '/^\W*(?:please\s+|por\s+favor\s+)?'
        . '(?:open|switch|go|move|change|work|abre|abrir|ve|vamos|cambia|cambiar|trabaja)\b/iu';

    private const array ALLOW_ONCE = ['yes', 'y', 'ok', 'once', 'allow', 'si', 'sí'];
    private const array ALLOW_ALWAYS = ['always', 'siempre'];
    private const array REJECT = ['no', 'n', 'reject', 'deny'];

    public function __construct(
        private readonly Agent $agent,
        private readonly ?Session $session,
        private readonly string $message,
        private readonly Users $user,
        private readonly ?HarnessTransport $transport = null,
        private readonly ?CodingChatCheckouts $checkouts = null,
    ) {
    }

    public function execute(): string
    {
        if ($this->session === null) {
            return 'A coding chat needs a conversation to answer in. Open it from the chat.';
        }

        $reply = $this->reply($this->session, trim($this->message));

        // The transcript is what the chat renders and reloads from. Recording the turn here also creates
        // the session's conversation, which every later turn delivery is appended to.
        new KanvasConversationStore()->logTurn(
            userId: $this->user->getId(),
            sessionId: (string) $this->session->uuid,
            agentClass: self::class,
            userMessage: $this->message,
            assistantResponse: $reply,
            agentId: $this->agent->getId(),
            participant: KanvasConversationStore::participantFor($this->session, $this->user, $this->agent),
        );

        return $reply;
    }

    private function reply(Session $chat, string $text): string
    {
        $live = AgentTaskSession::liveChatFor($this->agent, $chat);

        if (str_starts_with($text, '/')) {
            return $this->command($chat, $text, $live);
        }

        $requested = $this->switchRequestedIn($text, $live);

        if ($requested !== null) {
            return $this->switchTo($chat, $requested, $live);
        }

        if ($live !== null) {
            return $this->relay($live, $text);
        }

        $default = CodingChatSettingEnum::defaultRepoFor($this->agent);

        if ($default === null) {
            return "Which repository should I work in? Start with `/repo <App> <what to do>`.\n\n" . self::HELP;
        }

        return $this->open($chat, $default, $text);
    }

    private function command(Session $chat, string $text, ?AgentTaskSession $live): string
    {
        [$name, $rest] = array_pad(preg_split('/\s+/', $text, 2) ?: [], 2, '');

        return match (strtolower($name)) {
            '/repo' => $this->switchTo($chat, (string) $rest, $live),
            '/take' => $this->take($chat, (string) $rest, $live),
            '/status' => $this->status($live),
            '/stop' => $this->stop($live),
            '/end' => $this->end($live),
            default => self::HELP,
        };
    }

    /**
     * "Open Quorum-app", "go to … and open Quorum-app", "abre Quorum-app": the person is switching, and
     * passed to the agent it would go and work in that folder itself — outside its checkout, with no
     * lock and no branch. Only a request verb plus the name of a real checkout counts; a message that
     * merely mentions another app ("the button from Junkmaxy-app") still goes to the agent. The
     * machine is asked for its checkouts only when the verb is there.
     */
    private function switchRequestedIn(string $text, ?AgentTaskSession $live): ?string
    {
        if (preg_match(self::SWITCH_REQUEST, $text) !== 1) {
            return null;
        }

        $repo = ($this->checkouts ?? new CodingChatCheckouts($this->agent))->namedIn($text);

        return $repo !== null && $repo !== $live?->repo_slug ? $repo : null;
    }

    /**
     * One checkout per conversation: switching closes the current one first, which hands it back to the
     * factory with its work on its branch, exactly like `/end`.
     */
    private function switchTo(Session $chat, string $args, ?AgentTaskSession $live): string
    {
        [$repo, $instruction] = array_pad(preg_split('/\s+/', trim($args), 2) ?: [], 2, '');
        $repo = (string) $repo;
        $instruction = trim((string) $instruction);

        // The Mac's folders are case-insensitive: `quorum-app` and `Quorum-app` are one checkout and one lock.
        if ($live !== null && strcasecmp((string) $live->repo_slug, $repo) === 0) {
            return $instruction === ''
                ? 'Already working in **' . $repo . '**.'
                : $this->relay($live, $instruction);
        }

        if (preg_match(self::REPO_PATTERN, $repo) !== 1) {
            return 'Which repository? Use the checkout\'s folder name, e.g. `/repo Quorum-app fix the login spinner`.';
        }

        $closed = $live !== null ? new CloseCodingChatAction($live, notify: false)->execute() . "\n\n" : '';

        return $closed . $this->open($chat, $repo, $instruction);
    }

    /**
     * Moves a checkout here from another of this person's own open conversations — the way out of the
     * "already open in another conversation of yours" refusal. Someone else's conversation, or a workflow
     * task working in it, is theirs to end.
     */
    private function take(Session $chat, string $args, ?AgentTaskSession $live): string
    {
        $repo = (string) (preg_split('/\s+/', trim($args), 2) ?: [''])[0];

        if ($repo === '') {
            return 'Which app? For example `/take Quorum-app`.';
        }

        if ($live !== null && strcasecmp((string) $live->repo_slug, $repo) === 0) {
            return $this->switchTo($chat, $args, $live);
        }

        /** @var AgentTaskSession|null $holder */
        $holder = AgentTaskSession::query()
            ->fromApp($this->agent->app)
            ->fromCompany($this->agent->company)
            ->notDeleted()
            ->live()
            ->whereNotNull('chat_session_id')
            ->where('chat_session_id', '!=', $chat->getId())
            ->where('users_id', $this->user->getId())
            ->whereRaw('LOWER(repo_slug) = ?', [mb_strtolower($repo)])
            ->latest('id')
            ->first();

        if ($holder === null || $holder->isTaskRun()) {
            return 'None of your other conversations has **' . $repo . '** open. If something else is using it, '
                . 'the message that refused it says what.';
        }

        new PostCodingChatMessageAction(
            $holder,
            '↪ **' . $holder->repo_slug . '** moved to another conversation.'
            . ($holder->branch !== null ? ' Its work stays on branch `' . $holder->branch . '`.' : '')
        )->execute();
        new CloseCodingChatAction($holder, notify: false)->execute();

        return $this->switchTo($chat, $args, $live);
    }

    private function open(Session $chat, string $repo, string $instruction): string
    {
        if (preg_match(self::REPO_PATTERN, $repo) !== 1) {
            return '⚠️ `' . $repo . '` is not a checkout name. Fix the agent\'s default repository or use `/repo <App>`.';
        }

        try {
            new OpenCodingChatAction(
                agent: $this->agent,
                chat: $chat,
                repo: $repo,
                instruction: $instruction,
                requestedBy: $this->user,
            )->execute();
        } catch (ValidationException $e) {
            return '⚠️ ' . $e->getMessage();
        }

        return '⏳ Opening **' . $repo . '** on the factory Mac. It measures the baseline first, which can '
            . 'take a few minutes; I will post here when '
            . ($instruction === '' ? 'it is ready for your first instruction.' : 'the agent starts.');
    }

    private function relay(AgentTaskSession $live, string $text): string
    {
        $status = $live->harnessStatus();

        if ($status === HarnessStatusEnum::STARTING || ! $live->isProvisioned()) {
            return $this->holdUntilStarted($live, $text);
        }

        try {
            return match ($status) {
                HarnessStatusEnum::AWAITING_PERMISSION => $this->answerPermission($live, $text),
                HarnessStatusEnum::AWAITING_ANSWER => $this->answerQuestion($live, $text),
                HarnessStatusEnum::IDLE => $this->nextTurn($live, $text),
                HarnessStatusEnum::RUNNING => $this->steer($live, $text),
                default => 'The coding agent is busy (' . $status->value . '). Try again in a moment.',
            };
        } catch (Throwable $e) {
            report($e);

            return '⚠️ That did not reach the coding agent: ' . $e->getMessage();
        }
    }

    /**
     * Held for the launch to hand over when the agent starts. The launch takes what is held only after
     * the row stops saying STARTING, so the row is read again here: a message that landed after that
     * hand-over would otherwise wait for a launch that already happened.
     */
    private function holdUntilStarted(AgentTaskSession $live, string $text): string
    {
        HeldChatMessages::hold($live, $text);
        $live->refresh();

        if (! $live->isLive()) {
            HeldChatMessages::take($live);

            return '**' . $live->repo_slug . '** could not be opened; the message above says why.';
        }

        if ($live->harnessStatus() !== HarnessStatusEnum::STARTING && $live->isProvisioned()) {
            $held = HeldChatMessages::take($live);

            return $held === [] ? '⏳ On it.' : $this->relay($live, implode("\n\n", $held));
        }

        return '⏳ Still opening **' . $live->repo_slug . '** (started ' . $live->created_at?->diffForHumans()
            . '). I will pass this to the agent as soon as it starts.';
    }

    private function nextTurn(AgentTaskSession $live, string $text): string
    {
        $live->status = HarnessStatusEnum::RUNNING->value;
        $live->touchHeartbeat();
        $live->saveOrFail();

        try {
            // A checkout opened without an instruction has no opencode session yet; the first message makes it.
            if ($live->external_session_id === null) {
                $this->harness($live)->start($live, new HarnessPrompt(task: $text, policy: ''));
            } else {
                $this->harness($live)->ask($live, $text);
            }
        } catch (Throwable $e) {
            $live->status = HarnessStatusEnum::IDLE->value;
            $live->saveOrFail();

            throw $e;
        }

        PollHarnessSessionJob::dispatch($this->agent->app, $live->getId());

        return '⏳ On it.';
    }

    private function steer(AgentTaskSession $live, string $text): string
    {
        $this->harness($live)->steer($live, $text);

        return 'Added to the current turn.';
    }

    private function answerPermission(AgentTaskSession $live, string $text): string
    {
        $harness = $this->harness($live);
        $pending = $harness->poll($live)->permissions[0] ?? null;

        if ($pending === null) {
            return $this->steerPastAStaleWait($live, $text);
        }

        $decision = $this->decision($text);

        if ($decision === null) {
            return 'It is waiting for permission to run `' . $pending->describe() . '`. Reply **yes**, **always** or **no**.';
        }

        $harness->answerPermission($live, $pending->id, $decision);
        $this->markRunning($live);

        return match ($decision) {
            PermissionDecisionEnum::ONCE => 'Allowed once.',
            PermissionDecisionEnum::ALWAYS => 'Allowed for the rest of this chat.',
            PermissionDecisionEnum::REJECT => 'Refused.',
        };
    }

    private function answerQuestion(AgentTaskSession $live, string $text): string
    {
        $harness = $this->harness($live);
        $question = $harness->poll($live)->questions[0] ?? null;

        if ($question === null) {
            return $this->steerPastAStaleWait($live, $text);
        }

        $harness->answerQuestion($live, $question->id, $text);
        $this->markRunning($live);

        return 'Answer sent.';
    }

    /**
     * The row says the agent is waiting until the next poll tick, up to a minute after it was answered
     * and moved on. A message landing in that gap is meant for the running turn, not to be dropped.
     */
    private function steerPastAStaleWait(AgentTaskSession $live, string $text): string
    {
        $this->markRunning($live);

        return $this->steer($live, $text);
    }

    private function markRunning(AgentTaskSession $live): void
    {
        $live->status = HarnessStatusEnum::RUNNING->value;
        $live->touchHeartbeat();
        $live->saveOrFail();
    }

    private function decision(string $text): ?PermissionDecisionEnum
    {
        $word = mb_strtolower(trim($text, " \t\n\r\0\x0B.!"));

        return match (true) {
            in_array($word, self::ALLOW_ONCE, true) => PermissionDecisionEnum::ONCE,
            in_array($word, self::ALLOW_ALWAYS, true) => PermissionDecisionEnum::ALWAYS,
            in_array($word, self::REJECT, true) => PermissionDecisionEnum::REJECT,
            default => null,
        };
    }

    private function status(?AgentTaskSession $live): string
    {
        if ($live === null) {
            return 'No coding chat is open here. Start one with `/repo <App> <what to do>`.';
        }

        return '**' . $live->repo_slug . '**'
            . ($live->branch !== null ? ' · branch `' . $live->branch . '`' : '')
            . ' · ' . $live->status
            . ' · ~$' . number_format((float) $live->estimated_cost, 2);
    }

    private function stop(?AgentTaskSession $live): string
    {
        if ($live === null || $live->harnessStatus() !== HarnessStatusEnum::RUNNING) {
            return 'Nothing is running.';
        }

        $this->harness($live)->stop($live);

        return 'Stopping the current turn…';
    }

    private function end(?AgentTaskSession $live): string
    {
        if ($live === null) {
            return 'No coding chat is open here.';
        }

        return new CloseCodingChatAction($live, notify: false)->execute();
    }

    private function harness(AgentTaskSession $live): AgentHarness
    {
        return HarnessFactory::forSession($live, $this->transport);
    }
}
