<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Social\Channels\Models\Channel as ModelsChannel;
use Kanvas\Social\Enums\AppEnum;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Services\MessageFileConstrainerService;
use Kanvas\Social\Messages\Validations\ValidParentMessage;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CreateMessageAction
{
    use HasMutationUploadFiles;

    public bool $runWorkflow = true;

    public function __construct(
        public MessageInput $messageInput,
        public ?SystemModules $systemModule = null,
        public mixed $entityId = null,
    ) {
    }

    public function execute(): Message
    {
        return DB::connection('social')->transaction(function () {
            $privateAndLocked = in_array($this->messageInput->type->verb, ['agent-message', 'message'], true)
                && filter_var(
                    $this->messageInput->app->get(IntelligenceConfigurationEnum::PRIVATE_AND_LOCK_CONVERSATION_MESSAGES->value),
                    FILTER_VALIDATE_BOOL,
                );

            $data = [
                'apps_id' => $this->messageInput->app->getId(),
                'parent_id' => $this->messageInput->parent_id,
                'parent_unique_id' => $this->messageInput->parent_unique_id,
                'companies_id' => $this->messageInput->company->getId(),
                'users_id' => $this->messageInput->user->getId(),
                'message_types_id' => $this->messageInput->type->getId(),
                'message' => $this->messageInput->message,
                'reactions_count' => $this->messageInput->reactions_count,
                'comments_count' => $this->messageInput->comments_count,
                'total_liked' => $this->messageInput->total_liked,
                'total_disliked' => $this->messageInput->total_disliked,
                'total_saved' => $this->messageInput->total_saved,
                'total_shared' => $this->messageInput->total_shared,
                'ip_address' => $this->messageInput->ip_address,
                'is_public' => $privateAndLocked ? 0 : $this->messageInput->is_public,
                'slug' => $this->messageInput->slug,
                'is_locked' => $privateAndLocked ? 1 : $this->messageInput->is_locked,
            ];

            $validator = Validator::make($data, [
                'parent_id' => [new ValidParentMessage($this->messageInput->app->getId())],
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator->messages()->__toString());
            }

            if ($this->messageInput->parent_id == null || $this->messageInput->parent_id == 0) {
                $data['parent_id'] = null;
            }

            $message = Message::create($data);

            if (! empty($this->messageInput->custom_fields)) {
                $message->setCustomFields($this->messageInput->custom_fields);
                $message->saveCustomFields();
            }

            if (count($this->messageInput->tags)) {
                $message->syncTags($this->messageInput->tags);
            }

            if (count($this->messageInput->categories)) {
                $message->syncCategories($this->messageInput->categories);
            }

            if ($this->systemModule && $this->entityId !== null) {
                $associateMessage = new AssociateMessageToSystemModule(
                    $message,
                    $this->systemModule,
                    $this->entityId
                );
                $associateMessage->execute();
            }

            // Upload files before adding to channel so workflow can access them
            if (! empty($this->messageInput->files)) {
                $this->constrainMessageFiles();

                $this->handleFileUpload(
                    $message,
                    $this->messageInput->app,
                    $this->messageInput->user,
                    $this->messageInput->files
                );
            }

            if ($this->messageInput->channel_slug !== null) {
                $allowAppWideChannel = (bool) $this->messageInput->app->get(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value);

                $channel = ModelsChannel::where('slug', $this->messageInput->channel_slug)
                    ->where('apps_id', $this->messageInput->app->getId())
                    ->when(! $allowAppWideChannel, fn (Builder $q): Builder => $q->where('companies_id', $this->messageInput->company->getId()))
                    ->where('is_deleted', 0)
                    ->when($this->entityId !== null && $this->systemModule !== null, function (Builder $query) {
                        $query->where('entity_id', $this->entityId)
                            ->where('entity_namespace', $this->systemModule->model_name);
                    })
                    ->first();
                if ($channel) {
                    $channel->addMessage($message, $message->user);
                } else {
                    $modelName = $this->systemModule?->model_name;
                    new CreateChannelAction(new Channel(
                        apps: $message->app,
                        companies: $message->company,
                        users: $message->user,
                        //entity_id: $this->entityId ?? $message->getId(),
                        entity_id: $message->getId(),
                        //entity_namespace: $this->systemModule?->model_name ?? Message::class,
                        entity_namespace: Message::class,
                        name: $this->messageInput->channel_slug,
                        description: $this->messageInput->channel_slug,
                        slug: $this->messageInput->channel_slug,
                    ))->execute()->addMessage($message, $message->user);
                }
            }

            if ($this->runWorkflow) {
                $message->fireWorkflow(
                    WorkflowEnum::CREATED->value,
                    true,
                    [
                        'app' => $this->messageInput->app,
                    ]
                );
            }

            return $message;
        });
    }

    private function constrainMessageFiles(): void
    {
        $this->messageInput->files = MessageFileConstrainerService::constrain(
            $this->messageInput->app,
            $this->messageInput->type->verb,
            $this->messageInput->files,
        );
    }
}
