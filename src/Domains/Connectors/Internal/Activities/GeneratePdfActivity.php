<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Tasks\Actions\ChangeTaskEngagementItemStatusAction;
use Kanvas\ActionEngine\Tasks\Enums\TaskStatusEnum;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

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

                $pdfData = array_merge([
                    'app' => $app,
                ], $params);

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

                /**
                 * @todo MOVE THIS TO ITS OWN ACTIVITY
                 */
                if ($entity instanceof Message && isset($entity->message['checkListId'])) {
                    try {
                        $action = Action::getBySlug($entity->message['verb'], $entity->company);
                        $companyAction = CompanyAction::getByAction($action, $entity->company, $app);
                        $engagement = Engagement::getByMessageId($entity->getId());

                        $companyTaskList = TaskListItem::query()->where('companies_action_id', $companyAction->getId())
                            ->where('task_list_id', $entity->message['checkListId'])
                            ->where('is_deleted', 0)
                            ->first();

                        //$entity->message['checkListId'] = $entity->message['checkListId'];
                        if ($companyTaskList) {
                            new ChangeTaskEngagementItemStatusAction(
                                taskListItem: $companyTaskList,
                                lead: $engagement->lead,
                                status: TaskStatusEnum::COMPLETED->value,
                                user: $engagement->user,
                                app: $app,
                                company: $engagement->company,
                                message: $entity
                            )->execute();
                        }
                    } catch (Exception $e) {
                        $errorMessage = $e->getMessage() . $e->getTraceAsString();
                    }
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
