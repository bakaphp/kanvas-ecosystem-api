<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Internal\Handlers\InternalHandler;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Follows\Workflows\SendMessageNotificationToFollowersActivity;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\Support\Setup;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

class MessageNotificationTest extends TestCase
{
    public function testExecute(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $data = [
            'description' => 'As the 2024',
            'title' => 'What You Need to Know',
            'display_type' => [
                'id' => '1',
                'name' => 'bullet',
            ],
            'parts' => [
                [
                    'id' => 6934,
                    'title' => 'What You Need to Know',
                ],
            ],
            'type' => [
                'id' => '1',
                'name' => 'Single',
            ],
            'created_at' => 1729675973544,
            'updated_at' => 1729675973544,
        ];

        $createMessage = new CreateMessageAction(
            new MessageInput(
                $app,
                $user->getCurrentCompany(),
                $user,
                (new CreateMessageTypeAction(
                    new MessageTypeInput(
                        $app->getId(),
                        1,
                        'create',
                        'Create',
                        'Create',
                    )
                ))->execute(),
                $data,
            )
        );
        $message = $createMessage->execute();

        $socialSetup = new Setup(
            $app,
            $user,
            $user->getCurrentCompany()
        );
        $socialSetup->run();

        $region = Regions::fromApp($app)->fromCompany($company)->first();

        $integration = Integrations::firstOrCreate([
            'apps_id' => $app->getId(),
            'name' => IntegrationsEnum::INTERNAL->value,
            'config' => [],
            'handler' => InternalHandler::class,
        ]);

        $integrationDto = new IntegrationsCompany(
            integration: $integration,
            region: $region,
            company: $company,
            config: [],
            app: $app
        );

        $status = Status::where('slug', StatusEnum::ACTIVE->value)
        ->where('apps_id', 0)
        ->first();
        new CreateIntegrationCompanyAction($integrationDto, $user, $status)->execute();

        $activity = new SendMessageNotificationToFollowersActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($message, $app, [
            'message' => sprintf('New message from %s', $user->displayname),
            'title' => 'New Message',
            'subject' => 'New Message',
            'via' => ['database'],
            'metadata' => [
                'destination_type' => 'MESSAGE',
                'destination_event' => 'NEW_MESSAGE',
            ],
        ]);

        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('message_id', $result);
    }
}
