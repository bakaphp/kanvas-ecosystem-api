<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\DataTransferObject;

use Illuminate\Support\Str;
use Kanvas\Connectors\WordPress\Enums\PostStatusEnum;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Social\Messages\Models\Message;

/**
 * A wp/v2 post as Kanvas describes it, before term names and media URLs are exchanged for WP ids.
 *
 * `categories` / `tags` hold names or numeric ids interchangeably — the agent writing the message
 * knows the taxonomy by name, not by the site's internal ids.
 */
class WordPressPost
{
    private const int MAX_TITLE_LENGTH = 117;

    public function __construct(
        public readonly string $title,
        public readonly string $content,
        public readonly PostStatusEnum $status,
        public readonly ?string $excerpt = null,
        public readonly ?string $slug = null,
        public readonly ?string $date = null,
        public readonly ?int $authorId = null,
        public readonly array $categories = [],
        public readonly array $tags = [],
        public readonly ?string $featuredImageUrl = null,
        public readonly array $attachmentUrls = [],
        public readonly array $meta = [],
        public readonly ?string $commentStatus = null,
        public readonly ?string $pingStatus = null,
        public readonly bool $sticky = false,
        public readonly ?string $format = null,
        public readonly ?string $password = null,
    ) {
    }

    /**
     * @param array<string, mixed> $defaults  workflow-rule params + connector config, lowest precedence
     * @param array<string, mixed> $overrides editorial policy the message may not override — see
     *                                        PushMessageToWordPressAction::statusOverride()
     */
    public static function fromMessage(
        Message $message,
        array $defaults = [],
        array $overrides = []
    ): self {
        $body = $message->getMessage();
        $nested = is_array($body['wordpress'] ?? null) ? $body['wordpress'] : [];

        $data = array_merge(
            self::onlyPostKeys($defaults),
            self::onlyPostKeys($body),
            self::onlyPostKeys(self::agentEnvelope($body)),
            self::onlyPostKeys($nested),
            self::onlyPostKeys($overrides),
        );

        $content = self::resolveContent(
            $message,
            $body,
            $data
        );
        $attachments = $message->attachmentUrls();
        $images = $attachments['images'];

        $featuredImage = self::string($data['featured_image'] ?? null) ?? ($images[0] ?? null);

        $remainingFiles = array_values(array_filter(
            [...$images, ...$attachments['documents']],
            fn (string $url): bool => $url !== $featuredImage
        ));

        return new self(
            title: self::string($data['title'] ?? null) ?? self::deriveTitle($content, $message),
            content: $content,
            status: PostStatusEnum::tryFromMixed($data['status'] ?? null) ?? PostStatusEnum::DRAFT,
            excerpt: self::string($data['excerpt'] ?? null),
            slug: self::string($data['slug'] ?? null) ?? self::string($message->slug),
            date: self::string($data['date'] ?? null),
            authorId: isset($data['author_id']) ? (int) $data['author_id'] : null,
            categories: self::terms($data['categories'] ?? null) ?: $message->categories->pluck('name')->all(),
            tags: self::terms($data['tags'] ?? null) ?: $message->tags->pluck('name')->all(),
            featuredImageUrl: $featuredImage,
            attachmentUrls: self::terms($data['attachments'] ?? null) ?: $remainingFiles,
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
            commentStatus: self::string($data['comment_status'] ?? null),
            pingStatus: self::string($data['ping_status'] ?? null),
            sticky: (bool) ($data['sticky'] ?? false),
            format: self::string($data['format'] ?? null),
            password: self::string($data['password'] ?? null),
        );
    }

    public function toPayload(
        array $categoryIds,
        array $tagIds,
        ?int $featuredMediaId
    ): array {
        $optional = [
            'excerpt' => $this->excerpt,
            'slug' => $this->slug,
            'date' => $this->date,
            'author' => $this->authorId,
            'comment_status' => $this->commentStatus,
            'ping_status' => $this->pingStatus,
            'format' => $this->format,
            'password' => $this->password,
            'featured_media' => $featuredMediaId,
            'categories' => $categoryIds,
            'tags' => $tagIds,
            'meta' => $this->meta,
            'sticky' => $this->sticky,
        ];

        return [
            'title' => $this->title,
            'content' => $this->content,
            'status' => $this->status->value,
            // An unset field must be absent, not sent empty — WP treats an empty value as "clear
            // this", which would wipe terms and meta on an update.
            ...array_filter($optional, fn (mixed $value): bool => ! in_array($value, [null, '', [], false], true)),
        ];
    }

    /**
     * Recover the agent's structured reply from a message written by a channel responder.
     *
     * A channel reply lands as TEXT: ChatHelper picks ONE field out of the agent's JSON, so an agent
     * that wrote a whole post keeps only its body and loses title, terms and excerpt. `response_json`
     * is the decoded envelope the reply actions persist alongside that text. The string candidates
     * cover messages written before that existed, and any producer that stores the reply verbatim —
     * fence and all.
     */
    private static function agentEnvelope(array $body): array
    {
        if (is_array($body['response_json'] ?? null)) {
            return ChatHelper::firstRecord($body['response_json']) ?? [];
        }

        foreach (['response_json', 'response_text', 'responseText', 'content'] as $key) {
            $raw = self::string($body[$key] ?? null);

            if ($raw === null) {
                continue;
            }

            $decoded = ChatHelper::extractJsonEnvelope($raw);

            if ($decoded !== null) {
                return ChatHelper::firstRecord($decoded) ?? [];
            }
        }

        return [];
    }

    /**
     * Message bodies carry chat/agent bookkeeping alongside the post fields — take only what wp/v2
     * understands so an unrelated key can never reach the site.
     */
    private static function onlyPostKeys(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'title',
            'content',
            'excerpt',
            'slug',
            'status',
            'date',
            'author_id',
            'categories',
            'tags',
            'featured_image',
            'attachments',
            'meta',
            'comment_status',
            'ping_status',
            'sticky',
            'format',
            'password',
        ]));
    }

    /**
     * Message::contentText() falls back to the raw JSON body when it can't find a text key — a
     * useful rescue for legacy chat rows, but here it would publish the message's own JSON as the
     * post. Use it only for a genuinely non-object body, and read the object shape by key.
     */
    private static function resolveContent(
        Message $message,
        array $body,
        array $data
    ): string {
        $explicit = self::string($data['content'] ?? null);

        if ($explicit !== null) {
            return $explicit;
        }

        if ($body === []) {
            return trim($message->contentText());
        }

        foreach (['text', 'message', 'body'] as $key) {
            $value = self::string($body[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return '';
    }

    private static function deriveTitle(string $content, Message $message): string
    {
        $firstLine = trim(strtok(trim(strip_tags($content)), "\n") ?: '');

        return $firstLine !== ''
            ? Str::limit($firstLine, self::MAX_TITLE_LENGTH)
            : 'Kanvas message ' . $message->uuid;
    }

    /**
     * @return list<string|int>
     */
    private static function terms(mixed $value): array
    {
        $value = is_string($value) ? explode(',', $value) : $value;

        if (! is_array($value)) {
            return [];
        }

        $terms = array_filter(array_map(
            fn (mixed $term): string|int|null => is_int($term) ? $term : self::string($term),
            $value
        ), fn (string|int|null $term): bool => $term !== null);

        return array_values(array_unique($terms, SORT_REGULAR));
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
