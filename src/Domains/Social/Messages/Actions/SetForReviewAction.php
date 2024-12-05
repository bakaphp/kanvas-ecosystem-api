<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Notifications\Jobs\SendEmailToUserJob;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class SetForReviewAction
{
    public function __construct(
        protected Message $message
    ) {
    }

    public function execute(): mixed
    {
        $messageData = $this->message->message;
        //validate if message has for_review=true,is_reviewed=false and is_public=1
        // if ($messageData['for_review'] && ! $messageData['is_reviewed'] && $this->message->is_public) {
            //$this->message->setLock(); lock the message here

            // $reviewersList = explode(',', getenv('PROMPT_REVIEWERS_EMAILS')); //Later be gotta implement user groups
            // //send email to reviewers
            // $reviewers = UsersRepository::findUsersByArray(
            //     $reviewersList,
            //     $app,
            // );

            // foreach ($reviewers as $reviewer) {
            //     SendEmailToUserJob::dispatch(
            //         $reviewer,
            //         'Prompt pending for review',
            //         $app,
            //         [
            //             "body" => "{$reviewer->firstname} the prompt titled: {$this->message->message['title']} is in need for review"
            //         ]);
            // }

            $user = Users::find(1817);
            SendEmailToUserJob::dispatch(
            $user,
            'Prompt pending for review',
            [
                "body" => "{$user->firstname} the prompt titled: {$this->message->message['title']} is in need for review"
            ]);

            return $messageData['for_review'];

        // }
    }
}