<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;
use Throwable;

/**
 * Renders an invoice or quote to a PDF, attaches it to the document, and shapes the reply the same
 * way for both. Requires HasKanvasContext for the acting user (the file is uploaded as them).
 */
trait GeneratesScribeDocumentPdfForTool
{
    /**
     * @return array<string, mixed>
     */
    protected function generateDocumentPdf(Invoice|Quote $document, ?string $templateName = null): array
    {
        $user = $this->contextUser();

        if ($user === null) {
            return [
                'generated' => false,
                'reason' => 'no_user_context',
                'message' => 'No acting user in scope, so the PDF cannot be stored against this company.',
            ];
        }

        $service = new DocumentPdfService($document, $templateName);

        try {
            $file = $service->generate($user);
        } catch (Throwable $e) {
            return [
                'generated' => false,
                'reason' => 'render_failed',
                'message' => 'Could not render the PDF: ' . $e->getMessage(),
            ];
        }

        return [
            'generated' => true,
            'filesystem_id' => $file->getId(),
            'file_url' => $file->url,
            'file_name' => $file->name,
            'document_id' => $document->getId(),
            'message' => 'PDF generated and attached to the ' . ($document instanceof Quote ? 'quote' : 'invoice')
                . '. Hand the person a link with get_file_link, never the raw id.',
        ];
    }
}
