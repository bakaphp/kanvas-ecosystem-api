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
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class GeneratePdfActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $pdfTemplate = $params['template_pdf'] ?? null;
        $pdfFileName = $params['pdf_file_name'] ?? null;
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
                $errorMessage = $e->getMessage();
            }
        }

        return [
            'message' => 'Pdf generated successfully',
            'entity_id' => $entity->getId(),
            'file_id' => $pdfFile->getId(),
            'file_url' => $pdfFile->url,
            'error' => $errorMessage,
        ];
    }
}
