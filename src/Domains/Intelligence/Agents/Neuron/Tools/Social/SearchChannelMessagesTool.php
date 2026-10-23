<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Social;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\PresentsChannelMessages;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesChannelForTool;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Find messages on a channel by words, author or date. The window tool reads the newest page and
 * nothing else; a person asking "what did Jose say in #ops last week" needs the agent to reach past
 * it without paging, and to say "nothing" with confidence when nothing is there.
 */
#[AgentTool(name: 'Search Channel Messages', category: 'social')]
class SearchChannelMessagesTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use PresentsChannelMessages;
    use ResolvesChannelForTool;
    use TrackByInputs;

    protected string $name = 'search_channel_messages';

    protected ?string $description = 'Find messages on a channel by words, author and/or date range — how to answer '
        . '"what did Jose say in #ops", "what happened in #sales last week" or "did anyone mention the '
        . 'deploy" when read_channel_window is too shallow. Name the channel by channel_id, or by channel '
        . 'with its name (#ops) or Slack id (C0B4P1CH1ME). Returns newest first; each message carries its '
        . 'message_id and parent_id, so read_thread can show the replies under it and read_message_content '
        . 'the full text of one. The same search returns the same answer within a turn: if nothing matched, '
        . 'widen the dates or drop a filter instead of repeating it, and tell the person what you looked for.';

    public const int DEFAULT_LIMIT = 25;
    public const int MAX_LIMIT = 100;

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'channel_id',
                type: PropertyType::INTEGER,
                description: 'Which channel to search.',
                required: false,
            ),
            new ToolProperty(
                name: 'channel',
                type: PropertyType::STRING,
                description: 'The channel by name (#agents-logs), slug, or Slack channel id (C0B4P1CH1ME) '
                    . 'when you do not know its channel_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Words the message must contain. Case-insensitive, matched as a phrase.',
                required: false,
            ),
            new ToolProperty(
                name: 'author',
                type: PropertyType::STRING,
                description: 'Only messages written by this person: part of their name or their email.',
                required: false,
            ),
            new ToolProperty(
                name: 'since',
                type: PropertyType::STRING,
                description: 'Only messages on or after this date/time (ISO 8601, e.g. 2026-10-15 or 2026-10-15T09:00:00).',
                required: false,
            ),
            new ToolProperty(
                name: 'until',
                type: PropertyType::STRING,
                description: 'Only messages on or before this date/time (ISO 8601).',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'How many messages to return. Default ' . self::DEFAULT_LIMIT . ', maximum ' . self::MAX_LIMIT . '.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?int $channel_id = null,
        ?string $channel = null,
        ?string $query = null,
        ?string $author = null,
        ?string $since = null,
        ?string $until = null,
        ?int $limit = null,
    ): array {
        $inputs = [
            'channel_id' => $channel_id,
            'channel' => $this->channelReference($channel),
            'query' => trim((string) $query),
            'author' => trim((string) $author),
            'since' => trim((string) $since),
            'until' => trim((string) $until),
            'limit' => $this->clampLimit($limit, self::DEFAULT_LIMIT, self::MAX_LIMIT),
        ];

        return $this->oncePerTurn($inputs, fn (): array => $this->search($inputs));
    }

    /**
     * @param array{channel_id: int|null, channel: string, query: string, author: string, since: string, until: string, limit: int} $inputs
     * @return array<string, mixed>
     */
    private function search(array $inputs): array
    {
        if (! $this->hasTenantContext()) {
            return $this->channelToolWithoutTenant('channel search');
        }

        $channel = $this->resolveChannelOrError($inputs['channel_id'], $inputs['channel']);

        if (is_array($channel)) {
            return $channel;
        }

        if ($inputs['query'] === '' && $inputs['author'] === '' && $inputs['since'] === '' && $inputs['until'] === '') {
            return [
                'status' => 'error',
                'message' => 'Give at least one of query, author, since or until. For the latest messages with no filter use read_channel_window.',
            ];
        }

        try {
            $since = $inputs['since'] !== '' ? Carbon::parse($inputs['since']) : null;
            $until = $inputs['until'] !== '' ? Carbon::parse($inputs['until']) : null;
        } catch (Throwable) {
            return [
                'status' => 'error',
                'message' => 'since/until must be ISO 8601 dates such as 2026-10-15 or 2026-10-15T09:00:00.',
            ];
        }

        $messages = $channel->messages()
            ->notDeleted()
            ->when($inputs['query'] !== '', fn (Builder $q): Builder => $this->whereContains($q, $inputs['query']))
            ->when($inputs['author'] !== '', fn (Builder $q): Builder => $this->whereAuthor($q, $channel, $inputs['author']))
            ->when($since !== null, fn (Builder $q): Builder => $q->where('messages.created_at', '>=', $since))
            ->when($until !== null, fn (Builder $q): Builder => $q->where('messages.created_at', '<=', $until))
            ->orderByDesc('messages.id')
            ->limit($inputs['limit'])
            ->get();

        $result = [
            'status' => 'success',
            'channel_id' => $channel->getId(),
            'channel' => $channel->name,
            'filters' => array_filter([
                'query' => $inputs['query'],
                'author' => $inputs['author'],
                'since' => $since?->toIso8601String(),
                'until' => $until?->toIso8601String(),
            ]),
            'returned' => $messages->count(),
            'messages' => $messages->map(fn (Message $message): array => $this->describeChannelMessage($message))->all(),
        ];

        if ($messages->isEmpty()) {
            $result['message'] = 'No messages matched these filters on ' . $this->label($channel) . '. Searching again '
                . 'with the same filters will find nothing more: widen the dates, drop the author or use fewer words, '
                . 'or tell the person nothing matched.';
        }

        return $result;
    }

    private function whereContains(Builder $query, string $words): Builder
    {
        return $this->contentLike($query, '%' . $this->escapeLike(mb_strtolower($words)) . '%');
    }

    /**
     * JSON_UNQUOTE yields a binary-collated string, so a plain LIKE would be case-sensitive; lowering
     * both sides keeps "Deploy" and "deploy" the same word. The pattern is already lower-cased and escaped.
     */
    private function contentLike(Builder $query, string $pattern): Builder
    {
        return $query->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(messages.message, '$.content'))) LIKE ?", [$pattern]);
    }

    /**
     * Users live on another connection than messages, so a whereHas would silently match nothing:
     * resolve the ids first, among the people who wrote on this channel rather than the whole
     * platform. A speaker the connector could not match to a Kanvas account is stored under the
     * receiver's user with "Name: text" as the body, so the name prefix counts too.
     */
    private function whereAuthor(Builder $query, Channel $channel, string $author): Builder
    {
        $escaped = $this->escapeLike(mb_strtolower($author));
        $needle = '%' . $escaped . '%';

        $writerIds = $channel->messages()
            ->notDeleted()
            ->distinct()
            ->pluck('messages.users_id')
            ->all();

        $userIds = $writerIds === [] ? [] : Users::query()
            ->whereIn('id', $writerIds)
            ->where(
                fn (Builder $q): Builder => $q
                    ->whereRaw("LOWER(CONCAT_WS(' ', firstname, lastname)) LIKE ?", [$needle])
                    ->orWhereRaw('LOWER(displayname) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle])
            )
            ->pluck('id')
            ->all();

        return $query->where(
            fn (Builder $q): Builder => $q
                ->whereIn('messages.users_id', $userIds)
                ->orWhere(fn (Builder $prefix): Builder => $this->contentLike($prefix, $escaped . '%:%'))
        );
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function label(Channel $channel): string
    {
        return $channel->name . ' (channel_id ' . $channel->getId() . ')';
    }
}
