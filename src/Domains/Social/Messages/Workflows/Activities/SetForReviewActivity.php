<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Notifications\Jobs\SendEmailToUserJob;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Illuminate\Support\Facades\Log;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;

class SetForReviewActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $messageData = $entity->message;
        //validate if message has for_review=true,is_reviewed=false and is_public=1
        if ($messageData['for_review'] && ! $messageData['is_reviewed'] && $entity->is_public) {
            // $entity->setLock();

            $reviewersList = explode(',', getenv('PROMPT_REVIEWERS_EMAILS'));//Later be gotta implement user groups
            //send email to reviewers
            $reviewers = UsersRepository::findUsersByArray(
                $reviewersList,
                $app,
            );

            foreach ($reviewers as $reviewer) {
                SendEmailToUserJob::dispatch(
                    $reviewer,
                    'Prompt pending for review',
                    [
                        "body" => "{$reviewer->firstname} the prompt titled: {$entity->message['title']} is in need for review"
                    ]);
            }
        }

        return ["Done"];
    }
}