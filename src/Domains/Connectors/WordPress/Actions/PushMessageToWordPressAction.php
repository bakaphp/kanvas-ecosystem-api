<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Actions;

use Kanvas\Connectors\WordPress\DataTransferObject\WordPressPost;
use Kanvas\Connectors\WordPress\Enums\ConfigurationEnum;
use Kanvas\Connectors\WordPress\Enums\CustomFieldEnum;
use Kanvas\Connectors\WordPress\Enums\PostStatusEnum;
use Kanvas\Connectors\WordPress\RestClient;
use Kanvas\Connectors\WordPress\Services\WordPressMediaService;
use Kanvas\Connectors\WordPress\Services\WordPressTermService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;

/**
 * Publishes a Kanvas message as a post on the WordPress site connected to the message's company.
 *
 * Re-running is an update, not a second post — the wp post id lives on the message custom fields.
 */
class PushMessageToWordPressAction
{
    public function __construct(
        private readonly Message $message,
        private readonly array $defaults = [],
        private readonly ?RestClient $client = null,
    ) {
    }

    public function execute(): array
    {
        $app = $this->message->app;
        $company = $this->message->company;
        $client = $this->client ?? new RestClient($app, $company);

        $post = WordPressPost::fromMessage(
            $this->message,
            $this->defaults + $this->configDefaults(),
            $this->statusOverride()
        );

        if (trim(strip_tags($post->content)) === '') {
            throw new ValidationException('Message ' . $this->message->getId() . ' has no content to publish to WordPress');
        }

        $terms = new WordPressTermService($client, $this->allowsTermCreation());
        // Only the message's own files are named here; a url the agent wrote into the body keeps
        // falling back to its basename.
        $media = new WordPressMediaService($client, $this->message->fileNamesByUrl());

        $featuredMediaId = $post->featuredImageUrl !== null
            ? $media->upload($post->featuredImageUrl)
            : null;

        $attachmentIds = $media->uploadMany($post->attachmentUrls);

        $payload = $post->toPayload(
            $terms->resolveIds(WordPressTermService::CATEGORY_TAXONOMY, $post->categories),
            $terms->resolveIds(WordPressTermService::TAG_TAXONOMY, $post->tags),
            $featuredMediaId,
            $this->uploadedVideo($media, $post->videoUrl),
        );

        $existingPostId = $this->existingPostId($client);

        $result = $existingPostId !== null
            ? $client->updatePost($existingPostId, $payload)
            : $client->createPost($payload);

        $media->attachTo((int) $result['id']);

        $this->rememberPost(
            $client,
            $result,
            $featuredMediaId,
            $attachmentIds
        );

        return [
            'id' => (int) $result['id'],
            'link' => (string) ($result['link'] ?? ''),
            'status' => (string) ($result['status'] ?? $post->status->value),
            'title' => $post->title,
            'action' => $existingPostId !== null ? 'updated' : 'created',
            'featured_media' => $featuredMediaId,
            'categories' => array_map('intval', $result['categories'] ?? []),
            'tags' => array_map('intval', $result['tags'] ?? []),
            'attachments' => $attachmentIds,
            'media_failures' => $media->failures(),
        ];
    }

    /**
     * Both halves must land: no id, or a site that withheld the url, means the post ships as it would
     * have without the clip rather than with a `<video src="">`.
     *
     * @return array{id: int, url: string}|null
     */
    private function uploadedVideo(WordPressMediaService $media, ?string $videoUrl): ?array
    {
        if ($videoUrl === null) {
            return null;
        }

        $id = $media->upload($videoUrl);
        $url = $media->sourceUrl($videoUrl);

        return $id !== null && $url !== null ? ['id' => $id, 'url' => $url] : null;
    }

    /**
     * A message already pushed to a different site must not overwrite a post id that belongs to
     * that other site — treat a site change as a fresh publish.
     */
    private function existingPostId(RestClient $client): ?int
    {
        $postId = (int) $this->message->get(CustomFieldEnum::POST_ID->value);
        $siteUrl = (string) $this->message->get(CustomFieldEnum::POST_SITE_URL->value);

        if ($postId === 0 || $siteUrl !== $client->getSiteUrl()) {
            return null;
        }

        return $client->postExists($postId) ? $postId : null;
    }

    private function rememberPost(
        RestClient $client,
        array $result,
        ?int $featuredMediaId,
        array $attachmentIds
    ): void {
        $this->message->set(CustomFieldEnum::POST_ID->value, (int) $result['id']);
        $this->message->set(CustomFieldEnum::POST_URL->value, (string) ($result['link'] ?? ''));
        $this->message->set(CustomFieldEnum::POST_STATUS->value, (string) ($result['status'] ?? ''));
        $this->message->set(CustomFieldEnum::POST_SITE_URL->value, $client->getSiteUrl());

        if ($featuredMediaId !== null) {
            $this->message->set(CustomFieldEnum::FEATURED_MEDIA_ID->value, $featuredMediaId);
        }

        if ($attachmentIds !== []) {
            $this->message->set(CustomFieldEnum::MEDIA_IDS->value, $attachmentIds);
        }
    }

    /**
     * Publishing status is editorial POLICY, not content: a workflow rule that holds everything for
     * review must beat an agent that asked for `publish`. Every other rule param stays a default the
     * message can override — categories and tags describe the article, and the writer knows those
     * better than the rule does.
     *
     * Only the RULE's status is promoted; the connector configuration's default_post_status stays a
     * default, so a message that names its own status still wins over the site-wide setting.
     *
     * @return array<string, string>
     */
    private function statusOverride(): array
    {
        $status = $this->defaults['status'] ?? null;

        return is_string($status) && trim($status) !== '' ? ['status' => trim($status)] : [];
    }

    private function configDefaults(): array
    {
        $defaults = [
            'status' => $this->config(ConfigurationEnum::DEFAULT_POST_STATUS) ?? PostStatusEnum::DRAFT->value,
            'author_id' => $this->config(ConfigurationEnum::DEFAULT_AUTHOR_ID),
            'categories' => $this->config(ConfigurationEnum::DEFAULT_CATEGORIES),
            'tags' => $this->config(ConfigurationEnum::DEFAULT_TAGS),
        ];

        return array_filter($defaults, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function allowsTermCreation(): bool
    {
        return (bool) ($this->config(ConfigurationEnum::ALLOW_TERM_CREATION) ?? true);
    }

    private function config(ConfigurationEnum $key): mixed
    {
        return $this->message->company?->get($key->value) ?? $this->message->app->get($key->value);
    }
}
