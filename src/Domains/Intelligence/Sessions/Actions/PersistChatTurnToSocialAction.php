<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Enums\CaptionTargetEnum;
use Kanvas\Intelligence\Agents\Jobs\DescribeMessageAttachmentsJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Notifications\AgentReplyNotification;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\MarkLeadMessagesAsRespondedAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Workflows are NOT fired on these messages: connectors fire AFTER_ADDING_MESSAGE_TO_CHANNEL
 * to trigger an auto-reply, but this turn is already the reply, so firing would loop.
 */
class PersistChatTurnToSocialAction
{
    protected string $messageTypeVerb = 'ai-chat';

    /**
     * @param list<string> $images
     * @param list<Filesystem> $attachments Freshly uploaded files attached to the user prompt.
     * @param list<string> $documents Non-image native attachment URLs (audio / PDF) on the prompt.
     */
    public function __construct(
        protected readonly Session $session,
        protected readonly Agent $agent,
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Users $user,
        protected readonly string $userMessage,
        protected readonly string $assistantResponse,
        protected readonly array $images = [],
        protected readonly array $attachments = [],
        protected readonly ?Lead $currentLead = null,
        protected readonly array $documents = [],
    ) {
    }

    public function execute(): Message
    {
        $entity = $this->resolveEntity();
        $channel = $this->resolveChannel($entity);
        $type = MessageTypeService::getOrCreate($this->app, $this->messageTypeVerb);
        // Agent's own user → company AI user → human (last resort): message always has an author.
        $aiUser = $this->agent->user ?? $this->company->getAiAgentUser() ?? $this->user;

        $incoming = $this->createMessage(
            $type,
            $this->user,
            $this->userMessage,
            fromIa: false,
            images: $this->images,
        );

        // Use a unique tag per upload — AttachFilesystemAction replaces on tag collision, so
        // a shared "attachment" tag would let later uploads silently overwrite earlier ones.
        $uploadedNamesByUrl = [];
        foreach ($this->attachments as $filesystem) {
            $tag = $filesystem->name !== '' ? $filesystem->name : 'attachment-' . (string) $filesystem->getId();
            $incoming->addFile($filesystem, $tag);
            $uploadedNamesByUrl[(string) $filesystem->url] = (string) $filesystem->name;
        }

        // Client-provided URL media (already hosted on the CDN, not uploaded this turn) isn't in
        // $this->attachments, so it never lands on the message — attach it too so the file shows on
        // the message and feeds the cross-channel file rollup. addFileFromUrl only records the URL
        // reference (no re-download). Reuse the existing Filesystem's real name (set when the file
        // was first uploaded) for the attachment tag so filesystem_entities mirrors the file's
        // actual name instead of the hashed URL basename.
        $nativeMedia = array_values([...$this->images, ...$this->documents]);
        $filenames = [];
        foreach ($nativeMedia as $url) {
            $name = $uploadedNamesByUrl[$url] ?? $this->resolveFileName($url);
            $filenames[] = $name;

            if (! isset($uploadedNamesByUrl[$url])) {
                $incoming->addFileFromUrl($url, $name !== '' ? $name : $this->fileTagFromUrl($url), $this->app);
            }
        }

        // Describe the prompt's attachments (image/audio/PDF) with the agent's own model so later
        // turns — whose history is rebuilt from text only — still "remember" what was sent. Async:
        // the current turn already saw the real bytes, the description only has to land before the
        // next turn. The real filename lets the description name the file ("PDF \"invoice.pdf\": …").
        if ($nativeMedia !== []) {
            DescribeMessageAttachmentsJob::dispatch(
                $this->app,
                $this->agent,
                $this->user,
                CaptionTargetEnum::SOCIAL_MESSAGE,
                (string) $incoming->getId(),
                $nativeMedia,
                $filenames,
            );
        }

        $reply = $this->createMessage(
            $type,
            $aiUser,
            $this->assistantResponse,
            fromIa: true,
            parent: $incoming,
        );

        $reply->response_message_id = $incoming->getId();
        $reply->saveOrFail();

        if ($entity instanceof Model) {
            $incoming->addEntity($entity);
            $reply->addEntity($entity);
        }

        $channel->addMessage($incoming, $this->user);
        $channel->addMessage($reply, $aiUser);

        // Falls back to the People's active Lead so mid-turn create_lead
        // (request started lead-less, tool fired) still hits the Lead channel.
        $resolvedLead = $this->currentLead
            ?? match (true) {
                $entity instanceof Lead => $entity,
                $entity instanceof People => LeadsRepository::getPeopleActiveLead($entity),
                default => null,
            };

        if ($resolvedLead !== null && ! ($entity instanceof Lead)) {
            $leadChannelService = new LeadChannelService();
            $leadChannelService->attachMessageToLeadChannel(
                $incoming,
                $resolvedLead,
                $this->app,
                $this->company,
                $this->user,
            );
            $leadChannelService->attachMessageToLeadChannel(
                $reply,
                $resolvedLead,
                $this->app,
                $this->company,
                $aiUser,
            );
        }

        if ($resolvedLead !== null) {
            new MarkLeadMessagesAsRespondedAction($resolvedLead, $reply)->execute();
        }

        $this->user->notify(new AgentReplyNotification($reply, $this->agent, $aiUser));

        return $reply;
    }

    protected function resolveEntity(): ?Model
    {
        try {
            return $this->session->entity();
        } catch (Throwable) {
            return null;
        }
    }

    protected function resolveChannel(?Model $entity): Channel
    {
        if ($this->session->channel instanceof Channel) {
            return $this->session->channel;
        }

        $entityId = (int) ($entity?->getId() ?? $this->user->getId());
        $slug = SessionChannelService::createChannelSlug(
            'ai-assist',
            (int) $this->agent->getId() . '-' . $entityId
        );

        $channel = new CreateChannelAction(
            new ChannelDto(
                apps: $this->app,
                companies: $this->company,
                users: $this->user,
                name: 'AI chat with ' . $this->agent->name,
                description: 'AI conversation with agent ' . $this->agent->name,
                entity_id: $entityId,
                entity_namespace: $entity !== null ? $entity::class : Users::class,
                slug: $slug,
            )
        )->execute();

        $this->session->channel_id = $channel->getId();
        $this->session->saveOrFail();

        return $channel;
    }

    /**
     * The real name of the file behind a URL — the Filesystem row created when it was uploaded
     * carries the original name (e.g. "invoice.pdf") even though the URL is a hash. Empty string
     * when the URL was never uploaded through us (a raw external link).
     */
    private function resolveFileName(string $url): string
    {
        $filesystem = Filesystem::fromApp($this->app)
            ->fromCompany($this->company)
            ->where('url', $url)
            ->first();

        return $filesystem !== null ? (string) $filesystem->name : '';
    }

    private function fileTagFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $basename = is_string($path) && $path !== '' ? basename($path) : '';

        return $basename !== '' ? $basename : 'attachment-' . md5($url);
    }

    /**
     * @param list<string> $images
     */
    protected function createMessage(
        MessageType $type,
        Users $author,
        string $content,
        bool $fromIa,
        ?Message $parent = null,
        array $images = [],
    ): Message {
        $input = new MessageInput(
            app: $this->app,
            company: $this->company,
            user: $author,
            type: $type,
            message: AiChatMessagePayload::from([
                'content' => $content,
                'from_me' => $fromIa,
                'from_ia' => $fromIa,
                'session_id' => $this->session->uuid,
                'agent_id' => (int) $this->agent->getId(),
                'images' => $images,
            ])->toArray(),
            parent_id: $parent?->getId(),
            is_public: 1,
        );

        $action = new CreateMessageAction($input);
        $action->runWorkflow = false;

        return $action->execute();
    }
}
