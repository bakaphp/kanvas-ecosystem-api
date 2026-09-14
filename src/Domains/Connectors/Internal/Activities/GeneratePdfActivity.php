<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Tasks\Actions\ChangeTaskEngagementItemStatusAction;
use Kanvas\ActionEngine\Tasks\Enums\ChecklistPdfGenerationEnum;
use Kanvas\ActionEngine\Tasks\Enums\TaskStatusEnum;
use Kanvas\ActionEngine\Tasks\Events\ChecklistGeneratePdfEvent;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

#[WorkflowAction(
    name: 'Generate PDF From Template',
    description: 'Renders a PDF from a named template using the record\'s data and attaches it. Both the '
        . 'template and the file name must be configured on the rule — without either it does nothing '
        . 'and says so rather than failing.',
    integration: IntegrationsEnum::INTERNAL,
    params: [
        'template_pdf' => 'Name of the blade template to render. Required; without it the step is a no-op.',
        'pdf_file_name' => 'File name for the generated PDF. Required; without it the step is a no-op.',
    ],
)]
class GeneratePdfActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public const string CHECKLIST_PDF_CUSTOM_FIELD = 'checklist.generate.pdf';

    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $pdfTemplate = $params['template_pdf'] ?? null;
        $pdfFileName = $params['pdf_file_name'] ?? null;

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function ($buyerCompany, $app, $integrationCompany, $additionalParams) use ($pdfTemplate, $pdfFileName, $entity, $params): array {
                $errorMessage = null;

                if ($pdfTemplate === null) {
                    return [
                        'message' => 'No template configured to generate pdf',
                        'entity_id' => $entity->getId(),
                    ];
                }

                if ($pdfFileName === null) {
                    return [
                        'message' => 'No file name configured to generate pdf',
                        'entity_id' => $entity->getId(),
                    ];
                }

                $engagement = null;
                $taskListItem = null;

                if ($entity instanceof Message && isset($entity->message['checkListId'])) {
                    try {
                        $action = Action::query()
                            ->where('slug', $entity->message['verb'] ?? '')
                            ->notDeleted()
                            ->firstOrFail();

                        $companyAction = CompanyAction::getByAction($action, $entity->company, $app);
                        $engagement = Engagement::getByMessageId($entity->getId());

                        $taskListItem = TaskListItem::query()
                            ->where('companies_action_id', $companyAction->getId())
                            ->where('task_list_id', $entity->message['checkListId'])
                            ->where('is_deleted', 0)
                            ->first();
                    } catch (Exception $e) {
                        $errorMessage = $e->getMessage() . $e->getTraceAsString();
                    }
                }

                $this->trackChecklistPdf($engagement, $taskListItem, ChecklistPdfGenerationEnum::GENERATING);

                $pdfData = array_merge([
                    'app' => $app,
                ], $params);

                try {
                    $pdfFile = PdfService::generatePdfFromTemplate(
                        $app,
                        $entity->user,
                        $pdfTemplate,
                        $entity,
                        $pdfData
                    );

                    $entity->addFile($pdfFile, $pdfFileName);

                    //@todo any better way to do this?
                    if ($entity instanceof Message && $entity->parent) {
                        $entity->parent->addFile($pdfFile, $pdfFileName);
                    }
                } catch (Throwable $e) {
                    $this->trackChecklistPdf($engagement, $taskListItem, ChecklistPdfGenerationEnum::FAILED);

                    throw $e;
                }

                /**
                 * @todo MOVE THIS TO ITS OWN ACTIVITY
                 */
                if ($engagement !== null && $taskListItem !== null) {
                    try {
                        new ChangeTaskEngagementItemStatusAction(
                            taskListItem: $taskListItem,
                            lead: $engagement->lead,
                            status: TaskStatusEnum::COMPLETED->value,
                            user: $engagement->user,
                            app: $app,
                            company: $engagement->company,
                            message: $entity
                        )->execute();
                    } catch (Exception $e) {
                        $errorMessage = $e->getMessage() . $e->getTraceAsString();
                    }
                }

                // Cleared even when the status change failed: the PDF itself is attached, and the task
                // row has its own lead-tasks channel.
                $this->trackChecklistPdf($engagement, $taskListItem, null);

                if ($errorMessage !== null) {
                    return $this->failWorkflow([
                        'message' => 'Pdf generated with errors',
                        'entity_id' => $entity->getId(),
                        'file_id' => $pdfFile->getId(),
                        'file_url' => $pdfFile->url,
                        'error' => $errorMessage,
                    ]);
                }

                return [
                    'message' => 'Pdf generated successfully',
                    'entity_id' => $entity->getId(),
                    'file_id' => $pdfFile->getId(),
                    'file_url' => $pdfFile->url,
                    'error' => $errorMessage,
                ];
            },
            company: $entity->company
        );
    }

    /**
     * Upserts this task's entry in the lead's checklist PDF custom field (a null status removes it)
     * and tells the lead channel to re-read it. UI state only, so a failure here is reported and
     * never allowed to fail the PDF.
     */
    private function trackChecklistPdf(
        ?Engagement $engagement,
        ?TaskListItem $taskListItem,
        ?ChecklistPdfGenerationEnum $status
    ): void {
        $lead = $engagement?->lead;

        if ($taskListItem === null || ! $lead instanceof Lead) {
            return;
        }

        try {
            // Keyed by lead, not task: two checklist items on one lead can generate at the same time.
            Cache::lock('checklist_generate_pdf:' . $lead->getId(), 10)->block(10, function () use ($lead, $engagement, $taskListItem, $status): void {
                $entries = array_values(array_filter(
                    (array) $lead->get(self::CHECKLIST_PDF_CUSTOM_FIELD, []),
                    fn (mixed $entry): bool => is_array($entry) && (int) ($entry['task_id'] ?? 0) !== $taskListItem->getId()
                ));

                if ($status !== null) {
                    $entries[] = [
                        'action_id' => (int) $taskListItem->companyAction->actions_id,
                        'company_action_id' => (int) $taskListItem->companies_action_id,
                        'task_id' => $taskListItem->getId(),
                        'message_id' => (int) $engagement->message_id,
                        'status' => $status->value,
                    ];
                }

                // set() fires CREATE_CUSTOM_FIELD workflows from inside this running workflow, and the
                // same Lead instance goes to ChangeTaskEngagementItemStatusAction next, hence finally.
                $lead->disableWorkflows();

                try {
                    // Never set([]): get() treats an empty Redis value as a miss and returns the stale DB row.
                    if ($entries === []) {
                        $lead->del(self::CHECKLIST_PDF_CUSTOM_FIELD);
                    } else {
                        $lead->set(self::CHECKLIST_PDF_CUSTOM_FIELD, $entries);
                    }
                } finally {
                    $lead->enableWorkflows();
                }
            });

            ChecklistGeneratePdfEvent::dispatch((string) $lead->uuid);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
