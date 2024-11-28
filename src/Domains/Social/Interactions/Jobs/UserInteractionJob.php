<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\Actions\CreateUserInteractionAction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\DataTransferObject\UserInteraction;
use Kanvas\Social\Messages\Models\UserMessage;

class UserInteractionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public $failOnTimeout = false;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected Model $entity,
        protected string $interaction,
        protected ?string $note = null
    ) {
        $this->onQueue('user-interactions');
    }

    public function handle()
    {
        config(['laravel-model-caching.disabled' => true]);
        $this->overwriteAppService($this->app);

        //if its homepage we do the shit for now , where we get the top page user msg
        if ($this->entity instanceof $this->app) {
            $pageNumber = 1; // Replace with the page number you want (starting from 1)
            $userMessages = UserMessage::getFirstMessageFromPage($this->user, $this->app, $pageNumber);
            $this->entity = $userMessages ?: $this->entity;
        }

        $interaction = (new CreateInteraction(
            new Interaction(
                $this->interaction,
                $this->app,
                $this->interaction,
            )
        ))->execute();
        $createUserInteraction = new CreateUserInteractionAction(
            new UserInteraction(
                $this->user,
                $interaction,
                (string) $this->entity->getId(),
                get_class($this->entity),
                $this->note
            )
        );

        $createUserInteraction->execute(
            allowDuplicate: true,
            addToCache: false
        );

        //generate ser feed
        $generateUserFeed = $this->app->get('social-generate-user-feed-after-interaction') ?? true;
        if ($generateUserFeed) {
            GenerateUserMessageJob::dispatch(
                $this->app,
                $this->user->getCurrentCompany(),
                $this->user
            );
        }
    }
}
