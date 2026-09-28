<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Approvals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kanvas\Approvals\Contracts\ApproverResolverInterface;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Whoever is in the channels the message was posted to — the people who can actually see the draft.
 *
 * In Social, not `Kanvas\Approvals`, because it has to know what a Channel is and nothing in
 * `src/Kanvas/` may depend on a `Domains/` sibling — hence the `channel_members` registration from
 * AppServiceProvider rather than the registry's own table.
 *
 * The author is excluded by default: an agent posts its draft as itself, and an agent signing off its
 * own work is what approval mode exists to prevent. `{"exclude_author": false}` opts out.
 */
class ChannelMemberApproverResolver implements ApproverResolverInterface
{
    #[Override]
    public function resolve(Model $entity, array $config): Collection
    {
        if (! $entity instanceof Message) {
            return collect();
        }

        $excludeAuthor = (bool) ($config['exclude_author'] ?? true);

        return $entity->loadMissing('channels.users')->channels
            ->flatMap(fn (Channel $channel): Collection => $channel->users)
            ->reject(
                fn (Users $user): bool => $excludeAuthor && $user->getId() === $entity->users_id
            )
            ->unique(fn (Users $user): int => $user->getId())
            ->values();
    }
}
