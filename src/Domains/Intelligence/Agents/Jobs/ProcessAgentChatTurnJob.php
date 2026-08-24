<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Events\AgentChatFailedEvent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Runs one chat turn off the request thread: a turn that fans out over a list costs an LLM
 * round-trip per item and dies on the 120s request cap as a 504, while a worker gets 3750s.
 * The kernel still persists and broadcasts, so the client sees it exactly as on the sync path.
 *
 * Media rides as URLs + Filesystem ids, not a Spatie DTO — see the "never queue a Spatie Data DTO
 * holding Eloquent models" rule in CLAUDE.md.
 */
class ProcessAgentChatTurnJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /** Never retried: a turn's tool calls have real side effects (leads created, emails sent). */
    public int $tries = 1;

    /**
     * @param list<string> $images
     * @param list<string> $documents
     * @param list<int> $attachmentIds Filesystem ids for files uploaded with this turn.
     */
    public function __construct(
        public readonly Apps $app,
        public readonly Agent $agent,
        public readonly Session $session,
        public readonly Users $user,
        public readonly string $message,
        public readonly array $images = [],
        public readonly array $documents = [],
        public readonly array $attachmentIds = [],
        public readonly ?int $currentLeadId = null,
    ) {
        $this->onQueue('agent-chat');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        try {
            new AgentChatKernel(
                agent: $this->agent,
                session: $this->session,
                message: $this->message,
                user: $this->user,
                images: $this->images,
                attachments: $this->resolveAttachments(),
                currentLead: $this->resolveCurrentLead(),
                documents: $this->documents,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            AgentChatFailedEvent::dispatch(
                $this->agent,
                $this->session->uuid
            );
        }
    }

    /**
     * @return list<Filesystem>
     */
    private function resolveAttachments(): array
    {
        if ($this->attachmentIds === []) {
            return [];
        }

        return Filesystem::query()
            ->fromApp($this->app)
            ->whereIn('id', $this->attachmentIds)
            ->get()
            ->all();
    }

    private function resolveCurrentLead(): ?Lead
    {
        if ($this->currentLeadId === null) {
            return null;
        }

        return Lead::getByIdFromCompanyApp(
            $this->currentLeadId,
            $this->agent->company,
            $this->app
        );
    }
}
