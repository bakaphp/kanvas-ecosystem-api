<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;

class GeneratePdfActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $pdfTemplate = $params['template_pdf'] ?? null;
        $pdfFileName = $params['pdf_file_name'] ?? null;

        if (! $pdfTemplate) {
            return [
                'message' => 'No template configured to generate pdf',
                'entity_id' => $entity->getId(),
            ];
        }

        if (! $pdfFileName) {
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

        return [
            'message' => 'Pdf generated successfully',
            'entity_id' => $entity->getId(),
            'file_id' => $pdfFile->getId(),
            'file_url' => $pdfFile->url,
        ];
    }
}
