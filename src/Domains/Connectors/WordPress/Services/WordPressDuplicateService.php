<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Services;

use Baka\Support\Str;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\WordPress\DataTransferObject\PossibleDuplicate;
use Kanvas\Connectors\WordPress\DataTransferObject\WordPressPost;
use Kanvas\Connectors\WordPress\Enums\ConfigurationEnum;
use Kanvas\Connectors\WordPress\Enums\CustomFieldEnum;
use Kanvas\Connectors\WordPress\Enums\DuplicateMatchEnum;
use Kanvas\Connectors\WordPress\RestClient;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\VectorStores\TypesenseKnowledgeStore;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

/**
 * The per-message post id only turns a RE-RUN into an update — two messages carrying the same story
 * would each become a post. The claim is state on the message itself (custom fields), taken under a
 * lock before anything reaches the site, so two messages racing each other cannot both pass the check.
 * A merely similar story is not refused (it can be a legitimate follow-up); the caller holds it for
 * review instead.
 */
class WordPressDuplicateService
{
    private const string SOURCE_TYPE = 'wordpress_post';
    private const int DEFAULT_WINDOW_HOURS = 72;
    private const float DEFAULT_MIN_SCORE = 0.9;
    private const float MIN_TITLE_SIMILARITY = 0.85;
    private const int TITLE_CANDIDATES = 200;
    private const int SEMANTIC_CANDIDATES = 10;
    private const int EMBEDDED_CHARACTERS = 2000;
    private const int LOCK_WAIT_SECONDS = 10;

    /**
     * Must outlive the slowest claim: `isAbandoned()` asks the site whether a post was deleted, and
     * RestClient retries that for up to ~140s. A lock that expires mid-check lets a second copy through.
     */
    private const int LOCK_SECONDS = 180;

    /**
     * A claim with no post id this old belongs to a worker that died mid-publish without releasing it.
     */
    private const int STALE_CLAIM_MINUTES = 60;

    public function __construct(
        private readonly Message $message,
        private readonly RestClient $client,
    ) {
    }

    /**
     * @throws ValidationException when an identical story is already on the site or being published
     */
    public function claim(WordPressPost $post): void
    {
        $fingerprint = $this->fingerprint($post);

        try {
            Cache::lock('wordpress:story:' . $this->message->companies_id . ':' . $fingerprint, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, function () use ($fingerprint, $post): void {
                    $owner = $this->ownerOf($fingerprint);

                    if ($owner !== null && ! $this->isAbandoned($owner)) {
                        throw new ValidationException($this->duplicateReason($owner));
                    }

                    $owner?->del(CustomFieldEnum::POST_FINGERPRINT->value);

                    $this->message->set(CustomFieldEnum::POST_FINGERPRINT->value, $fingerprint);
                    $this->message->set(CustomFieldEnum::POST_TITLE->value, $post->title);
                });
        } catch (LockTimeoutException) {
            throw new ValidationException('An identical story is already being published to WordPress');
        }
    }

    public function findPossibleDuplicate(WordPressPost $post): ?PossibleDuplicate
    {
        $titles = $this->recentlyPublishedTitles();

        return $this->semanticMatch($post, $titles) ?? $this->titleMatch($post, $titles);
    }

    /**
     * A failed publish must not leave a claim behind, or the retry is refused as a duplicate of a post
     * that never existed.
     */
    public function release(): void
    {
        $this->message->del(CustomFieldEnum::POST_FINGERPRINT->value);
        $this->message->del(CustomFieldEnum::POST_TITLE->value);

        if (! $this->semanticCheckEnabled()) {
            return;
        }

        try {
            $this->store()->deleteBySource($this->scope(), self::SOURCE_TYPE, $this->sourceName());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Defaults to the app's knowledge-stack switch: an app that has embeddings and Typesense configured
     * gets the semantic check without a second setting.
     */
    protected function semanticCheckEnabled(): bool
    {
        $configured = ConfigurationEnum::SEMANTIC_DUPLICATE_CHECK->valueFor($this->message->app, $this->message->company)
            ?? $this->message->app->get(KnowledgeConfigurationEnum::ENABLED->value);

        return filter_var($configured, FILTER_VALIDATE_BOOL);
    }

    /**
     * The story is indexed BEFORE the search, so two reworded stories published at the same moment
     * still find each other.
     *
     * @return array<int, float> message id => similarity
     */
    protected function semanticNeighbours(WordPressPost $post): array
    {
        $embedding = KnowledgeComponents::embedder($this->message->app)->embed(
            $post->title . "\n\n" . mb_substr(Str::htmlToText($post->content), 0, self::EMBEDDED_CHARACTERS)
        );
        $store = $this->store();

        $store->upsert([
            new KnowledgeDocument(
                id: self::SOURCE_TYPE . '-' . $this->message->getId(),
                content: $post->title,
                metadata: [
                    'embedding' => $embedding,
                    'sourceType' => self::SOURCE_TYPE,
                    'sourceName' => $this->sourceName(),
                    'apps_id' => $this->message->apps_id,
                    'companies_id' => $this->message->companies_id,
                    'source_type' => self::SOURCE_TYPE,
                    'source_id' => (string) $this->message->getId(),
                    'created_at' => time(),
                ],
            ),
        ]);

        $hits = $store->search(
            $embedding,
            $this->scope(),
            topK: self::SEMANTIC_CANDIDATES,
            minScore: $this->minScore(),
        );

        $neighbours = [];

        foreach ($hits as $hit) {
            $neighbours[(int) ($hit['metadata']['source_id'] ?? 0)] = $hit['score'];
        }

        return $neighbours;
    }

    /**
     * @param array<int, string> $titles
     */
    private function semanticMatch(WordPressPost $post, array $titles): ?PossibleDuplicate
    {
        if (! $this->semanticCheckEnabled()) {
            return null;
        }

        try {
            $neighbours = $this->semanticNeighbours($post);
        } catch (Throwable $e) {
            // An embeddings or Typesense outage must not stop the newsroom; the title check still runs.
            report($e);

            return null;
        }

        // The recent claims, not the vector hit, decide recency: a released story has no title claim.
        return $this->bestMatch(array_intersect_key($neighbours, $titles), DuplicateMatchEnum::SEMANTIC);
    }

    /**
     * @param array<int, string> $titles
     */
    private function titleMatch(WordPressPost $post, array $titles): ?PossibleDuplicate
    {
        $title = self::normalize($post->title);
        $scores = [];

        foreach ($titles as $messageId => $candidateTitle) {
            similar_text($title, self::normalize($candidateTitle), $percent);

            if ($percent / 100 >= self::MIN_TITLE_SIMILARITY) {
                $scores[$messageId] = $percent / 100;
            }
        }

        return $this->bestMatch($scores, DuplicateMatchEnum::TITLE);
    }

    /**
     * @param array<int, float> $scores message id => similarity
     */
    private function bestMatch(array $scores, DuplicateMatchEnum $match): ?PossibleDuplicate
    {
        arsort($scores);
        $messageId = array_key_first($scores);

        if ($messageId === null) {
            return null;
        }

        $message = Message::query()->fromApp($this->message->app)->whereKey($messageId)->first();

        return $message !== null ? new PossibleDuplicate($message, $match, $scores[$messageId]) : null;
    }

    /**
     * Read off the custom fields directly: the title claim's `updated_at` is when the story was last
     * claimed, which is what the window measures. The app filter comes from the messages themselves,
     * since custom fields carry no apps_id.
     *
     * @return array<int, string> message id => title
     */
    private function recentlyPublishedTitles(): array
    {
        $titles = AppsCustomFields::query()
            ->where('companies_id', $this->message->companies_id)
            ->where('model_name', Message::class)
            ->where('name', CustomFieldEnum::POST_TITLE->value)
            ->where('is_deleted', 0)
            ->where('entity_id', '!=', $this->message->getId())
            ->where('updated_at', '>=', now()->subHours($this->windowHours()))
            ->latest('id')
            ->limit(self::TITLE_CANDIDATES)
            ->pluck('value', 'entity_id')
            ->all();

        if ($titles === []) {
            return [];
        }

        $messageIds = Message::query()
            ->fromApp($this->message->app)
            ->whereIn('id', array_keys($titles))
            ->pluck('id')
            ->all();

        return array_map('strval', array_intersect_key($titles, array_flip($messageIds)));
    }

    private function ownerOf(string $fingerprint): ?Message
    {
        return Message::getByCustomFieldBuilderTransactionSafe(CustomFieldEnum::POST_FINGERPRINT->value, $fingerprint, $this->message->company)
            ->fromApp($this->message->app)
            ->whereKeyNot($this->message->getId())
            ->first();
    }

    /**
     * An editor who deleted a bad post must be able to have the story published again.
     */
    private function isAbandoned(Message $owner): bool
    {
        $postId = (int) $owner->get(CustomFieldEnum::POST_ID->value);

        if ($postId > 0) {
            return $this->client->postWasDeleted($postId);
        }

        $claimedAt = $owner->getCustomField(CustomFieldEnum::POST_FINGERPRINT->value)?->updated_at;

        return $claimedAt === null || $claimedAt->lt(now()->subMinutes(self::STALE_CLAIM_MINUTES));
    }

    private function duplicateReason(Message $owner): string
    {
        $postId = (int) $owner->get(CustomFieldEnum::POST_ID->value);

        if ($postId > 0) {
            return sprintf(
                'Identical to WordPress post #%d (%s), already published from message %d',
                $postId,
                (string) $owner->get(CustomFieldEnum::POST_URL->value),
                $owner->getId()
            );
        }

        return 'An identical story is already being published to WordPress from message ' . $owner->getId();
    }

    /**
     * A dedicated collection: published posts must never surface in an agent's knowledge retrieval.
     */
    private function store(): TypesenseKnowledgeStore
    {
        $app = $this->message->app;

        return KnowledgeComponents::store($app, collection: config('scout.prefix') . 'wordpress_posts_' . $app->getId());
    }

    private function scope(): KnowledgeScope
    {
        return KnowledgeScope::forTenant((int) $this->message->apps_id, (int) $this->message->companies_id);
    }

    private function sourceName(): string
    {
        return 'message:' . $this->message->getId();
    }

    private function windowHours(): int
    {
        $hours = (int) ConfigurationEnum::DUPLICATE_WINDOW_HOURS->valueFor($this->message->app, $this->message->company);

        return $hours > 0 ? $hours : self::DEFAULT_WINDOW_HOURS;
    }

    private function minScore(): float
    {
        $score = ConfigurationEnum::DUPLICATE_MIN_SCORE->valueFor($this->message->app, $this->message->company);

        return is_numeric($score) ? (float) $score : self::DEFAULT_MIN_SCORE;
    }

    /**
     * Site-bound, so the same story on a company's new site is not a duplicate of the old one. Case,
     * accents, punctuation and whitespace are formatting, not a different story.
     */
    private function fingerprint(WordPressPost $post): string
    {
        return hash(
            'sha256',
            $this->client->getSiteUrl() . "\n" . self::normalize($post->title . ' ' . Str::htmlToText($post->content))
        );
    }

    private static function normalize(string $value): string
    {
        return Str::slug($value, ' ');
    }
}
