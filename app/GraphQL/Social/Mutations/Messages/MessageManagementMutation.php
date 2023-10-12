<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Channels\Repositories\ChannelRepository;
use Kanvas\Social\Distribution\Jobs\SendToChannelJob;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Enums\DistributionType;
use Kanvas\Social\Messages\Jobs\FillUserMessage;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessageActivityType;
use Kanvas\Social\Messages\Repositories\MessageRepository;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;

class MessageManagementMutation
{
    /**
     * create
     *
     * @param  mixed $request
     * @return void
     */
    public function create(mixed $root, array $request): Message
    {
        $parent = null;
        if (key_exists('parent_id', $request['input'])) {
            $parent = MessageRepository::getById((int)$request['input']['parent_id']);
        }

        $messageType = MessagesTypesRepository::getById((int)$request['input']['message_types_id']);
        $systemModule = SystemModules::getById((int)$request['input']['system_modules_id']);

        $request['input']['parent_id'] = $parent ? $parent->id : 0;
        $request['input']['parent_unique_id'] = $parent?->uuid;
        $request['input']['apps_id'] = app(Apps::class)->id;
        $request['input']['companies_id'] = auth()->user()->getCurrentCompany()->getId();
        $request['input']['users_id'] = auth()->user()->id;
        $data = MessageInput::from($request['input']);
        $action = new CreateMessageAction($data, $systemModule, $request['input']['entity_id']);
        $message = $action->execute();

        if(! key_exists('distribution', $request['input'])) {
            return $message;
        }
        $distributionType = DistributionType::from($request['input']['distribution']['distributionType']);

        if($distributionType->value == DistributionType::ALL->value) {
            $channels = key_exists('channels', $request['input']['distribution']) ? $request['input']['distribution']['channels'] : [];
            $this->distributeChannels($channels, $message, auth()->user());
            $this->distributeUsers($message);

        } elseif($distributionType->value == DistributionType::Channels->value) {
            $channels = key_exists('channels', $request['input']['distribution']) ? $request['input']['distribution']['channels'] : [];
            $this->distributeChannels($channels, $message, auth()->user());
        } elseif($distributionType->value == DistributionType::Users->value) {
            $this->distributeUsers($message);
        }

        return $message;

    }

    private function distributeChannels(array $channels, Message $message, Users $user): void
    {
        $channelsDataBase = [];
        if($channels) {
            foreach($channels as $channel) {
                $channelsDataBase[] = ChannelRepository::getById((int)$channel, $user);
            }
        } else {
            $channelsDataBase = $user->channels;
        }
        SendToChannelJob::dispatch($channelsDataBase, $message)->onQueue('kanvas-social');
    }

    private function distributeUsers(Message $message): void
    {
        $activity = [];

        $activityType = UserMessageActivityType::where('name', 'follow')->firstOrFail();
        $activity = [
                    'username' => '',
                    'entity_namespace' => '',
                    'text' => ' ',
                    'type' => $activityType->id,
            ];

        FillUserMessage::dispatch($message, $message->user, $activity)->onQueue('message');

    }
}
