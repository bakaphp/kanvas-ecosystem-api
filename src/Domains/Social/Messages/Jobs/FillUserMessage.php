<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Social\Messages\Actions\CreateUserMessageAction;
use Kanvas\Social\Messages\Models\Message;

class FillUserMessage // implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Message $message,
        public EloquentModel $entityFollow,
        public array $activity = []
    ) {
        $this->onQueue('kanvas-social');
    }

    public function handle()
    {
        $followers = UsersFollowsRepository::getFollowersBuilder($this->entityFollow)->paginate(25);

        foreach ($followers as $follower) {
            $action = new CreateUserMessageAction(
                $this->message,
                $follower,
                $this->activity
            );
            $action->execute();
        }
    }
}
