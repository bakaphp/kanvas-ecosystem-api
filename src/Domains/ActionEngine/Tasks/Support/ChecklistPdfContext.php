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
 * The checklist a PDF-generating message belongs to, resolved once so the activity can both track
 * the generation up front and advance the task afterwards without repeating the lookups.
 *
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
     * Null means the message simply isn't a checklist submission — the PDF still generates.
     *
     * A throw means it claims to be one but its wiring is broken, which the activity turns into
     * `failWorkflow`.
     *
     * @throws ModelNotFoundException
     */
    public static function fromMessage(Message $message, AppInterface $app): ?self
    {
        $verb = $message->message['verb'] ?? null;

        // getBySlug is nullable and getByAction's first parameter is not, so a missing verb or an
        // Action soft-deleted after the engagement was created would raise a TypeError — an Error,
        // which the activity's `catch (Exception)` does not catch, so it escapes to Sentry and drops
        // the generated file's id from the response. Throwing keeps it on the failWorkflow path.
        $action = $verb === null ? null : Action::getBySlug($verb, $message->company);

        if ($action === null) {
            throw new ModelNotFoundException('Action not found');
        }

        $companyAction = CompanyAction::getByAction($action, $message->company, $app);

        // Resolved before the task item so a missing engagement still surfaces as a failure, the way
        // it did when these lookups lived inline in the activity.
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
