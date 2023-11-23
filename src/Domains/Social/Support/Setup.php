<?php

declare(strict_types=1);

namespace Kanvas\Social\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Str;
use Kanvas\Social\Enums\StateEnums;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\UsersFollows\Actions\CreateFollowAction;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;
use Kanvas\Users\Actions\CreateUserLinkedSourcesAction;
use Kanvas\Users\Models\Sources;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Notifications\Actions\CreateNotificationTypesMessageLogicAction;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;

class Setup
{
    /**
     * Constructor.
     */
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected CompanyInterface $company
    ) {
    }

    /**
     * Setup all the default inventory data for this current company.
     */
    public function run(): bool
    {
        $createSystemModule = new CreateInCurrentAppAction($this->app);
        $createSystemModule->execute(Interactions::class);

        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::LIKE->getValue(),
                $this->app,
                ucfirst((string) StateEnums::LIKE->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();

        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::DISLIKE->getValue(),
                $this->app,
                ucfirst((string) StateEnums::DISLIKE->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();

        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::SAVE->getValue(),
                $this->app,
                ucfirst((string) StateEnums::SAVE->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();
        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::REACTION->getValue(),
                $this->app,
                ucfirst((string) StateEnums::REACTION->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();
        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::FOLLOW->getValue(),
                $this->app,
                ucfirst((string) StateEnums::FOLLOW->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();
        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::COMMENT->getValue(),
                $this->app,
                ucfirst((string) StateEnums::COMMENT->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();
        $createInteractions = new CreateInteraction(
            new Interaction(
                (string) StateEnums::SHARE->getValue(),
                $this->app,
                ucfirst((string) StateEnums::SHARE->getValue()),
            )
        );

        $defaultInteraction = $createInteractions->execute();

        $createFollow = new CreateFollowAction(
            $this->user,
            $this->user,
            $this->company,
        );

        $createFollow->execute();

        $source = Sources::latest()->first();
        $createUserLinkedSource = new CreateUserLinkedSourcesAction(
            $this->user,
            $source->getId(),
            (string)Str::uuid(),
            (string)Str::uuid(),
            $this->user->displayname,
        );

        $createUserLinkedSource->execute();

        $messageTypeInput = MessageTypeInput::from([
            'apps_id' => $this->app->getId(),
            'languages_id' => 1,
            'name' => "entity",
            'verb' => "entity",
            'template' => '',
            'template_plura' => ''
        ]);

        $createMessageType = new CreateMessageTypeAction($messageTypeInput);
        $messageType = $createMessageType->execute();

        $notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent(2,$messageType->verb,'creation',$this->app);
        $logic = '{
            "conditions": "message.is_public == 1 and message.is_published == 1"
        }';

        $createNotificationTypeMessageLogic = new CreateNotificationTypesMessageLogicAction(
            $this->app,
            $messageType,
            $notificationType,
            $logic
        );

        $createNotificationTypeMessageLogic->execute();

        

        return $defaultInteraction instanceof Interactions;
    }
}
