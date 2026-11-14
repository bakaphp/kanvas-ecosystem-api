<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Channels\Models\Channel;

/**
 * The Social channel a conversation is filed in: one per company and slug, whoever gets there first,
 * the inbound pipeline or an outbound delivery. The slug alone is the conversation's identity, which
 * is why this is not CreateChannelAction: that one matches on the channel's entity too.
 *
 * An existing channel is returned as found. Which entity it points at is the caller's decision.
 */
final class FindOrCreateConversationChannelAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly string $slug
    ) {
    }

    /**
     * Parts of a burst arrive as parallel jobs, so creation is serialised on a cache lock — never on
     * the row: `channels` has no unique index on the slug, and a locking read of a row that does not
     * exist yet only locks the gap, which deadlocks the insert that follows (see CreateChannelAction).
     *
     * @param array<string, mixed> $attributes what a new channel is created with, beside its
     *                                         workspace and slug
     */
    public function execute(array $attributes): Channel
    {
        $findOrCreate = fn (): Channel => $this->find() ?? Channel::create([
            ...$attributes,
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'slug' => $this->slug,
        ]);

        try {
            return Cache::lock($this->lockKey(), 10)->block(5, $findOrCreate);
        } catch (LockTimeoutException) {
            // A duplicate serves a contended conversation better than a lost message does.
            return $findOrCreate();
        }
    }

    private function find(): ?Channel
    {
        return Channel::query()
            ->fromApp($this->app)
            ->fromCompany($this->company->getId())
            ->where('slug', $this->slug)
            ->orderByDesc('last_message_id')
            ->first();
    }

    private function lockKey(): string
    {
        return "communication:conversation:{$this->app->getId()}:{$this->company->getId()}:{$this->slug}";
    }
}
