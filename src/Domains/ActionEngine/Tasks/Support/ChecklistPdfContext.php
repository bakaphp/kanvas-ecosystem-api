<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Support;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;

/**
 * Not a Spatie Data DTO on purpose: it holds Eloquent models, which would make it unsafe to store on
 * a queued job.
 */
readonly class ChecklistPdfContext
{
    public function __construct(
        public Engagement $engagement,
        public TaskListItem $taskListItem
    ) {
    }

    /**
     * Null when the message isn't wired to a checklist item; throws when its action or engagement is
     * missing, which the activity reports through failWorkflow.
     *
     * @throws ModelNotFoundException
     */
    public static function fromMessage(Message $message, AppInterface $app): ?self
    {
        $action = Action::query()
            ->where('slug', $message->message['verb'] ?? '')
            ->notDeleted()
            ->firstOrFail();

        $companyAction = CompanyAction::getByAction($action, $message->company, $app);
        $engagement = Engagement::getByMessageId($message->getId());

        $taskListItem = TaskListItem::query()
            ->where('companies_action_id', $companyAction->getId())
            ->where('task_list_id', $message->message['checkListId'])
            ->where('is_deleted', 0)
            ->first();

        if ($taskListItem === null || ! $engagement->lead instanceof Lead) {
            return null;
        }

        return new self(engagement: $engagement, taskListItem: $taskListItem);
    }
}
