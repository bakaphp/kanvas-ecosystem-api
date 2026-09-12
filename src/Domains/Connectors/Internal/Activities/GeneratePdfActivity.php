<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\ActionEngine\Tasks\Actions\ChangeTaskEngagementItemStatusAction;
use Kanvas\ActionEngine\Tasks\Actions\TrackChecklistPdfGenerationAction;
use Kanvas\ActionEngine\Tasks\Enums\ChecklistPdfGenerationEnum;
use Kanvas\ActionEngine\Tasks\Enums\TaskStatusEnum;
use Kanvas\ActionEngine\Tasks\Support\ChecklistPdfContext;
use Kanvas\Filesystem\Services\PdfService;
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

                $checklistContext = null;

                if ($entity instanceof Message && isset($entity->message['checkListId'])) {
                    try {
                        $checklistContext = ChecklistPdfContext::fromMessage($entity, $app);
                    } catch (Exception $e) {
                        $errorMessage = $e->getMessage() . $e->getTraceAsString();
                    }
                }

                if ($checklistContext !== null) {
                    new TrackChecklistPdfGenerationAction(context: $checklistContext, status: ChecklistPdfGenerationEnum::GENERATING)->execute();
                }

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
                    if ($checklistContext !== null) {
                        new TrackChecklistPdfGenerationAction(context: $checklistContext, status: ChecklistPdfGenerationEnum::FAILED)->execute();
                    }

                    throw $e;
                }

                /**
                 * @todo MOVE THIS TO ITS OWN ACTIVITY
                 */
                if ($checklistContext !== null) {
                    try {
                        new ChangeTaskEngagementItemStatusAction(
                            taskListItem: $checklistContext->taskListItem,
                            lead: $checklistContext->engagement->lead,
                            status: TaskStatusEnum::COMPLETED->value,
                            user: $checklistContext->engagement->user,
                            app: $app,
                            company: $checklistContext->engagement->company,
                            message: $entity
                        )->execute();
                    } catch (Exception $e) {
                        $errorMessage = $e->getMessage() . $e->getTraceAsString();
                    }

                    // Cleared even when the status change failed: the PDF itself generated and is
                    // attached, so leaving a spinner up for an unrelated failure is worse than the
                    // task row lagging — that lands on its own lead-tasks channel.
                    new TrackChecklistPdfGenerationAction(context: $checklistContext, status: null)->execute();
                }

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
}
