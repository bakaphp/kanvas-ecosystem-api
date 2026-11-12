<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Sow\Services;

use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Sow\DataTransferObject\StatementOfWork;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Users\Models\Users;

/**
 * Template + SOW message + quote → HTML → PDF, attached to the quote as `quote_sow`.
 *
 * The template is the company layout version the SOW names (CompanySowTemplateService), else the
 * `scribe-sow` row (company row first, then the app's, then the platform default TemplatesRepository falls
 * back to). With no row at all the packaged Twig file renders through the same sandbox, so a tenant never
 * needs seeding before its first SOW. The quote half of the context
 * comes from DocumentPdfService::viewData(), so the SOW prints the same issuer, customer, lines and
 * totals the quote PDF does.
 */
final class QuoteSowPdfService
{
    public const string TEMPLATE_NAME = 'scribe-sow';
    public const string FIELD_NAME = 'quote_sow';
    public const string PACKAGED_TEMPLATE = 'views/pdf/scribe-sow.twig';

    public function __construct(
        private readonly Quote $quote,
        private readonly Message $sowMessage,
    ) {
    }

    public function generate(Users $user): Filesystem
    {
        $file = PdfService::htmlToPdf(
            app: $this->quote->app,
            user: $user,
            html: $this->render(),
            fileName: $this->fileName(),
            company: $this->quote->company,
        );

        $this->quote->addFile($file, self::FIELD_NAME);

        return $file;
    }

    /**
     * The SOW names the company layout version it was written with; a version since deleted falls back to stock.
     */
    public function render(): string
    {
        $renderer = new RenderTemplateAction($this->quote->app, $this->quote->company);
        $version = StatementOfWork::fromMessage($this->sowMessage)->template;
        $template = $version !== null
            ? new CompanySowTemplateService($this->quote->app, $this->quote->company)->find($version)
            : null;

        return $template !== null
            ? $renderer->renderTemplate($template, $this->viewData())
            : $renderer->renderNamedOrPackaged(self::TEMPLATE_NAME, $this->viewData(), self::PACKAGED_TEMPLATE);
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $sow = StatementOfWork::fromMessage($this->sowMessage);
        $total = (float) $this->quote->total_native;
        $amounts = $sow->paymentAmounts($total);

        $paymentRows = [];
        foreach ($sow->paymentSchedule as $index => $line) {
            $paymentRows[] = [
                'label' => $line['label'],
                'due' => $line['when'] === StatementOfWork::WHEN_ON_SIGNATURE
                    ? 'On signature'
                    : Carbon::parse((string) $line['date'])->toFormattedDateString(),
                'percent' => $total > 0 ? round($amounts[$index] / $total * 100, 2) : null,
                'amount' => number_format($amounts[$index], 2),
            ];
        }

        return [
            ...new DocumentPdfService($this->quote)->viewData(),
            'title' => 'Statement of Work',
            'revision' => $this->revisionNumber(),
            'sow' => [
                ...$sow->toPayload($this->quote->getId()),
                'timeline' => [
                    'start' => $sow->timelineStart !== null ? Carbon::parse($sow->timelineStart)->toFormattedDateString() : null,
                    'end' => $sow->timelineEnd !== null ? Carbon::parse($sow->timelineEnd)->toFormattedDateString() : null,
                ],
            ],
            'payment_rows' => $paymentRows,
            'quote_total' => number_format($total, 2),
        ];
    }

    public function fileName(): string
    {
        $reference = $this->quote->quote_number ?? ('draft-' . $this->quote->getId());

        return 'sow-' . (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $reference)
            . '-r' . $this->revisionNumber() . '.pdf';
    }

    private function revisionNumber(): int
    {
        $revision = 1;
        $message = $this->sowMessage;

        while ($message->parent_id !== null && $revision < 100) {
            $message = Message::query()->whereKey($message->parent_id)->first();

            if ($message === null) {
                break;
            }

            $revision++;
        }

        return $revision;
    }
}
