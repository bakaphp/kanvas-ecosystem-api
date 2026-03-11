<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\DistributeChannelAction;
use Kanvas\Social\Messages\Actions\DistributeToUsers;
use Kanvas\Social\Messages\Actions\UpdateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Enums\DistributionTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\WorkflowEnum;

class MessageManagementMutation
{
    use HasMutationUploadFiles;

    public function create(mixed $root, array $request): Message
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $messageData = $request['input'];

        $rules = [
            'system_modules_id' => 'nullable',
            'entity_id' => [
                'nullable',
                Rule::requiredIf(function () use ($messageData) {
                    return array_key_exists('system_modules_id', $messageData) && ! $messageData['system_modules_id'] !== null;
                }),
            ],
        ];

        $validator = Validator::make($messageData, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        try {
            $messageType = MessagesTypesRepository::getByVerb($messageData['message_verb'], $app);
        } catch (ModelNotFoundException $e) {
            $messageTypeDto = MessageTypeInput::from([
                'apps_id' => $app->getId(),
                'name' => $messageData['message_verb'],
                'verb' => $messageData['message_verb'],
            ]);
            $messageType = new CreateMessageTypeAction($messageTypeDto)->execute();
        }

        $systemModuleId = $messageData['system_modules_id'] ?? null;

        if (Str::isUuid($systemModuleId)) {
            $systemModule = SystemModules::getByUuid($systemModuleId, $app);
        } else {
            $systemModule = $systemModuleId ? SystemModules::getById((int)$systemModuleId, $app) : null;
        }

        $messageData['ip_address'] = request()->ip();
        $data = MessageInput::fromArray(
            $messageData,
            $user,
            $messageType,
            $company,
            $app
        );

        $action = new CreateMessageAction(
            $data,
            $systemModule,
            $messageData['entity_id'] ?? null
        );
        $action->runWorkflow = false;
        $message = $action->execute();

        $message->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            [
               'app' => $app,
            ]
        );

        if (! key_exists('distribution', $messageData)) {
            return $message;
        }

        $distributionType = DistributionTypeEnum::from($messageData['distribution']['distributionType']);

        if ($distributionType->value == DistributionTypeEnum::ALL->value) {
            $channels = key_exists('channels', $messageData['distribution']) ? $messageData['distribution']['channels'] : [];
            (new DistributeChannelAction($channels, $message, $user))->execute();
            (new DistributeToUsers($message))->execute();
        } elseif ($distributionType->value == DistributionTypeEnum::Channels->value) {
            $channels = key_exists('channels', $messageData['distribution']) ? $messageData['distribution']['channels'] : [];
            (new DistributeChannelAction($channels, $message, $user))->execute();
        } elseif ($distributionType->value == DistributionTypeEnum::Followers->value) {
            (new DistributeToUsers($message))->execute();
        }

        return $message;
    }

    public function update(mixed $root, array $request): Message
    {
        $app = app(Apps::class);
        $user = auth()->user();
        /** @var Message $message */
        $message = Message::getById((int) $request['id'], $app);

        if (! $message->canEdit($user)) {
            throw new AuthenticationException('You are not allowed to edit this message');
        }

        $input = $request['input'];

        if (! empty($input['message_verb'] ?? null)) {
            try {
                $messageType = MessagesTypesRepository::getByVerb($input['message_verb'], $message->app);
            } catch (ModelNotFoundException $e) {
                $messageTypeDto = MessageTypeInput::from([
                    'apps_id' => $message->app->getId(),
                    'name' => $input['message_verb'],
                    'verb' => $input['message_verb'],
                ]);
                $messageType = new CreateMessageTypeAction($messageTypeDto)->execute();
            }
        }

        return new UpdateMessageAction(
            $message,
            new MessageInput(
                app: $message->app,
                company: $message->company,
                user: $message->user,
                type: $messageType ?? $message->messageType,
                message: $input['message'] ?? $message->message,
                is_public: $input['is_public'] ?? $message->is_public,
                tags: $input['tags'] ?? [],
                categories: $input['categories'] ?? [],
            ),
        )->execute();
    }

    public function delete(mixed $root, array $request): bool
    {
        $message = Message::getById((int)$request['id'], app(Apps::class));
        if (! $message->canDelete(auth()->user())) {
            throw new AuthenticationException('You are not allowed to delete this message');
        }

        if ($message->delete()) {
            $message->unsearchableSync();

            return true;
        }

        return false;
    }

    public function deleteMultiple(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $messages = Message::fromApp($app)->whereIn('id', $request['ids'])->get();

        $total = 0;

        foreach ($messages as $message) {
            if (! $message->canEdit($user)) {
                throw new AuthenticationException('You are not allowed to delete this message');
            }
            $message->delete();
            $total++;
        }

        return $total > 0;
    }

    public function deleteAll(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        return Message::fromApp($app)->where('users_id', $user->getId())->delete() > 0;
    }

    public function attachTopicToMessage(mixed $root, array $request): Message
    {
        $message = Message::getById((int)$request['id'], app(Apps::class));
        $message->topics()->attach($request['topicId']);

        return $message;
    }

    public function detachTopicToMessage(mixed $root, array $request): Message
    {
        $message = Message::getById((int)$request['id'], app(Apps::class));
        $message->topics()->detach($request['topicId']);

        return $message;
    }

    public function attachFileToMessage(mixed $root, array $request): Message
    {
        $app = app(Apps::class);
        $message = Message::getById((int)$request['message_id'], $app);

        if (($message->user->getId() !== auth()->user()->getId()) && ! auth()->user()->isAdmin()) {
            throw new Exception('The message does not belong to the authenticated user');
        }

        return $this->uploadFileToEntity(
            model: $message,
            app: $app,
            user: auth()->user(),
            request: $request
        );
    }

    public function recoverMessage(mixed $root, array $request): Message
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $message = Message::withTrashed()->where('id', $request['id'])->where('users_id', $user->getId())->fromApp($app)->firstOrFail();
        $message->restore();

        return $message;
    }
}
