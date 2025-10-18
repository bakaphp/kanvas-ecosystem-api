<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\Actions\NotifiableUsersChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;
use Kanvas\Social\MessagesTypes\Models\MessageTypes;
use Kanvas\Users\Models\Users;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Actions\CreateUserAction;
class NotifiableUsersChannelActionTest extends TestCase
{
    public function testNotifiableUsersChannelAction(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $registerData = RegisterInput::from([
            'email' => fake()->unique()->safeEmail(),
            'password' => fake()->password(),
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'displayname' => fake()->userName(),
        ]);
        $userSecondary = (new CreateUserAction($registerData, $app))->execute();
        $company = $user->getCurrentCompany();
        $channelDto = ChannelDto::from([
            'apps' => $app,
            'companies' => $company,
            'users' => $user,
            'entity_id' => 1,
            'entity_namespace' => 'Tests\TestCase',
            'name' => 'Test Channel',
            'slug' => 'test-channel',
        ]);
        $channel = (new CreateChannelAction($channelDto))->execute();
        $messageType = MessageType::firstOrFail();
        $messageData = MessageInput::from([
            'apps' => $app,
            'companies' => $company,
            'users' => $user,
            'channels' => $channel,
            'message' => 'This is a test message',
            'type' => $messageType,
            'app' => $app,
            'user' => $user,
            'company' => $company,
        ]);
        $message = (new CreateMessageAction($messageData))->execute();
        
        $channel->addMessage($message);
        $channel->users()->attach($userSecondary->getId());
        new NotifiableUsersChannelAction($channel, $message)->execute();
        $this->assertTrue(true);
    }
}
