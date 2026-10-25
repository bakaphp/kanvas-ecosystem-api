<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Social\Channels\Models\Channel;

/**
 * Look up a Social Channel by id or by how a person names it, scoped to the tool's app + company,
 * returning the Channel OR a structured error array the LLM can act on. Mirrors ResolvesMessageForTool.
 *
 * A reference is the name a human would type ("#agents-logs"), the channel's slug, or the id of the
 * room on the connector side (a Slack "C0B4P1CH1ME"): connector channels carry that id as the tail of
 * their slug, so the agent can answer "what happened in C0B4…" without ever learning a Kanvas id.
 */
trait ResolvesChannelForTool
{
    /**
     * @return Channel|array{status: string, message: string}
     */
    protected function resolveChannelOrError(?int $channelId, ?string $reference): Channel|array
    {
        $reference = $this->channelReference($reference);

        if ($channelId !== null && $channelId > 0) {
            $channel = $this->findChannelById($channelId);

            if ($channel instanceof Channel) {
                return $channel;
            }

            return [
                'status' => 'error',
                'message' => 'Channel ' . $channelId . ' does not exist in this company.',
            ];
        }

        if ($reference === '') {
            return [
                'status' => 'error',
                'message' => 'Name the channel: pass channel_id, or channel with its name (#ops), slug or Slack id.',
            ];
        }

        $channel = $this->findChannelByReference($reference);

        if ($channel instanceof Channel) {
            return $channel;
        }

        return [
            'status' => 'error',
            'message' => 'No channel named "' . $reference . '" in this company. The name must match the '
                . 'room as it is listed to you (for Slack: "#name" or the C… channel id). Do not retry '
                . 'with the same value.',
        ];
    }

    /**
     * One normalisation for every tool that takes a `channel` argument, so the run key, the repeat
     * guard and the lookup all see the same value.
     */
    protected function channelReference(mixed $reference): string
    {
        return strtolower(trim((string) $reference));
    }

    protected function findChannelById(int $channelId): ?Channel
    {
        return $this->tenantChannels()->whereKey($channelId)->first();
    }

    /**
     * Exact name or slug first; the connector-id tail only for something shaped like one, so "#logs"
     * can never land on an unrelated "wa-group-logs".
     */
    protected function findChannelByReference(string $reference): ?Channel
    {
        $bare = ltrim($reference, '#');

        if ($bare === '') {
            return null;
        }

        $channel = $this->tenantChannels()
            ->where(
                fn (Builder $query): Builder => $query
                    ->whereIn('name', [$bare, '#' . $bare])
                    ->orWhere('slug', strtolower($bare))
            )
            ->orderByDesc('last_message_id')
            ->first();

        if ($channel instanceof Channel || preg_match('/^[A-Z][A-Z0-9]{8,}$/i', $bare) !== 1) {
            return $channel;
        }

        return $this->tenantChannels()
            ->where('slug', 'like', '%-' . strtolower($bare))
            ->orderByDesc('last_message_id')
            ->first();
    }

    private function tenantChannels(): Builder
    {
        return Channel::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted();
    }
}
