<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Services;

use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Services\AdminLinkService;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\AgentConversation;

/**
 * What to tell a person when the factory refuses a checkout another session holds: whose conversation
 * or which task has it, a link to that conversation when it is their own, and how to get it back. The
 * lock only names a session uuid, which means nothing to anyone.
 *
 * A holder outside the refused session's app and company is never described, only called "elsewhere":
 * every environment shares the Mac's locks, so the uuid may belong to another tenant.
 */
class CheckoutHolderNotice
{
    public function __construct(
        private readonly AgentTaskSession $refused,
        private readonly string $holderUuid,
    ) {
    }

    public function message(): string
    {
        $repo = '**' . $this->refused->repo_slug . '**';
        $holder = $this->holder();

        if ($holder === null) {
            return '⚠️ ' . $repo . ' is in use outside this Kanvas: another environment, or someone running '
                . '`akira` on the factory Mac. It has to be freed there first.';
        }

        $since = $holder->created_at?->diffForHumans() ?? 'earlier';

        if ($holder->isTaskRun()) {
            return '⚠️ ' . $repo . ' is busy with the task "' . ($holder->task?->parent?->title ?? 'a workflow task')
                . '" (started ' . $since . '). It frees itself when that task finishes; try again then.';
        }

        if ($holder->users_id !== $this->refused->users_id) {
            return '⚠️ ' . $repo . ' is open in ' . ($holder->user?->fullName() ?? 'someone else') . '\'s conversation with '
                . ($holder->agent?->name ?? 'a coding agent') . ' (since ' . $since . '). Ask them to send `/end` there.';
        }

        return '⚠️ ' . $repo . ' is already open in another conversation of yours' . $this->withAgent($holder)
            . ' (opened ' . $since . ')' . $this->linkLine($holder) . ".\n\n" . $this->howToFree($holder);
    }

    private function holder(): ?AgentTaskSession
    {
        /** @var AgentTaskSession|null */
        return AgentTaskSession::query()
            ->where('uuid', $this->holderUuid)
            ->fromApp($this->refused->apps_id)
            ->fromCompany($this->refused->companies_id)
            ->first();
    }

    private function withAgent(AgentTaskSession $holder): string
    {
        return $holder->agent_id !== $this->refused->agent_id && $holder->agent !== null
            ? ' with ' . $holder->agent->name
            : '';
    }

    private function howToFree(AgentTaskSession $holder): string
    {
        $branch = $holder->branch !== null ? ' Its work stays on branch `' . $holder->branch . '`.' : '';

        // A task run cannot take anything over: there is nobody to type the command.
        if ($this->refused->isTaskRun()) {
            return 'Send `/end` in that conversation, then run this task again.' . $branch;
        }

        return 'Send `/take ' . $this->refused->repo_slug . '` here to close it there and continue in this '
            . 'conversation.' . $branch;
    }

    private function linkLine(AgentTaskSession $holder): string
    {
        $url = $this->linkTo($holder);

        return $url !== null ? ': [go to that conversation](' . $url . ')' : '';
    }

    /**
     * The console opens an assistant's conversation from `?assistant=&session=`, `session` being the
     * conversation row the chat's turns are recorded in (`KanvasConversationStore::logTurn` keys it by
     * the chat session's uuid). Looked up, never created: a link must not open a conversation.
     */
    private function linkTo(AgentTaskSession $holder): ?string
    {
        $chat = $holder->chatSession;
        $app = $holder->app;

        if ($chat === null || $app === null || $holder->agent_id === null) {
            return null;
        }

        $conversationId = AgentConversation::query()
            ->where('apps_id', $holder->apps_id)
            ->where('companies_id', $holder->companies_id)
            ->where('agent_id', $holder->agent_id)
            ->where('title', (string) $chat->uuid)
            ->orderBy('id')
            ->value('id');

        if ($conversationId === null) {
            return null;
        }

        return new AdminLinkService()->build(
            $app,
            AdminLinkSectionEnum::CONTROL_CENTER_ASSISTANTS,
            query: ['assistant' => $holder->agent_id, 'session' => (string) $conversationId],
        );
    }
}
