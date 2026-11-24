<?php

declare(strict_types=1);

namespace Tests\Scribe\Quotes;

use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AttachQuoteFileTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateQuoteSowTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GetQuoteSowTool;
use Kanvas\Scribe\Quotes\Actions\CreateQuoteRevisionAction;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\DataTransferObject\Quote as QuoteData;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteLine as QuoteLineData;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Sow\Services\QuoteSowPdfService;
use Kanvas\Scribe\Quotes\Sow\Services\SowMessageTypeService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Support\TemplateSandbox;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class QuoteSowTest extends ScribeTestCase
{
    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'social'];

    public function test_a_consistent_sow_is_saved_as_a_sow_message_and_the_quote_points_at_it(): void
    {
        $quote = $this->draftQuote('Sow Saved Co');

        $result = $this->generate($quote, renderPdf: false);

        $this->assertTrue($result['saved'], json_encode($result));
        $quote->refresh();
        $this->assertSame($result['sow_message_id'], $quote->sow_message_id);

        $message = Message::query()->whereKey($quote->sow_message_id)->firstOrFail();
        $this->assertSame(SowMessageTypeService::VERB, $message->messageType->verb);
        $this->assertEquals(SowMessageTypeService::rules(), json_decode((string) $message->messageType->message_schema, true));
        $this->assertSame($quote->getId(), $message->message['quote_id']);
        $this->assertSame('Build the customer mobile app.', $message->message['summary']);
        $this->assertNull($message->parent_id);
    }

    public function test_a_second_sow_is_a_new_revision_chained_to_the_first(): void
    {
        $quote = $this->draftQuote('Sow Revised Co');

        $first = $this->generate($quote, renderPdf: false);
        $second = $this->generate($quote->refresh(), renderPdf: false, overrides: ['summary' => 'Build the app, phase two.']);

        $this->assertTrue($second['saved'], json_encode($second));
        $this->assertNotSame($first['sow_message_id'], $second['sow_message_id']);
        $this->assertSame($first['sow_message_id'], $second['previous_sow_message_id']);
        $this->assertSame($second['sow_message_id'], $quote->refresh()->sow_message_id);
        $this->assertSame(
            'Build the customer mobile app.',
            Message::query()->whereKey($first['sow_message_id'])->firstOrFail()->message['summary'],
            'An earlier revision is never edited in place.',
        );
    }

    public function test_a_quote_revision_carries_the_sow_pointer(): void
    {
        $quote = $this->draftQuote('Sow Revision Pointer Co');
        $sow = $this->generate($quote, renderPdf: false);
        $quote->refresh();

        $revision = new CreateQuoteRevisionAction(
            originalQuote: $quote,
            newRevisionData: new QuoteData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $quote->customer,
                lines: new DataCollection(QuoteLineData::class, [
                    new QuoteLineData(description: 'Discovery workshop', quantity: 1, unit_price_native: 1000.0),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame($sow['sow_message_id'], $revision->sow_message_id);
    }

    public function test_the_payment_schedule_must_add_up_to_the_quote_total(): void
    {
        $quote = $this->draftQuote('Sow Percent Co');

        $result = $this->generate($quote, renderPdf: false, overrides: [
            'payment_schedule' => [
                ['label' => 'Deposit', 'when' => 'on_signature', 'percent' => 50],
                ['label' => 'Balance', 'when' => 'date', 'date' => '2026-07-15', 'percent' => 40],
            ],
        ]);

        $this->assertFalse($result['saved']);
        $this->assertSame('sow_inconsistent', $result['reason']);
        $this->assertStringContainsString('add up to 90.00%', implode(' ', $result['problems']));
        $this->assertNull($quote->refresh()->sow_message_id, 'A refused SOW writes nothing.');
    }

    public function test_amounts_must_add_up_to_the_quote_total(): void
    {
        $quote = $this->draftQuote('Sow Amount Co');

        $result = $this->generate($quote, renderPdf: false, overrides: [
            'payment_schedule' => [
                ['label' => 'Deposit', 'when' => 'on_signature', 'amount' => 1000],
                ['label' => 'Balance', 'when' => 'date', 'date' => '2026-07-15', 'amount' => 2000],
            ],
        ]);

        $this->assertFalse($result['saved']);
        $this->assertStringContainsString('quote total is USD 4000.00', implode(' ', $result['problems']));
    }

    public function test_every_quote_line_must_be_covered_by_a_deliverable_or_scope_item(): void
    {
        $quote = $this->draftQuote('Sow Coverage Co');

        $result = $this->generate($quote, renderPdf: false, overrides: [
            'scope' => ['Workshops with the team'],
            'deliverables' => [
                ['name' => 'Discovery workshop report', 'acceptance_criteria' => 'Signed off by the customer.'],
            ],
        ]);

        $this->assertFalse($result['saved']);
        $problems = implode(' ', $result['problems']);
        $this->assertStringContainsString('("Mobile app build") is not covered', $problems);
        $this->assertStringNotContainsString('("Discovery workshop") is not covered', $problems);
    }

    public function test_payment_dates_must_fall_inside_the_timeline(): void
    {
        $quote = $this->draftQuote('Sow Timeline Co');

        $result = $this->generate($quote, renderPdf: false, overrides: [
            'payment_schedule' => [
                ['label' => 'Deposit', 'when' => 'on_signature', 'percent' => 50],
                ['label' => 'Balance', 'when' => 'date', 'date' => '2026-12-01', 'percent' => 50],
            ],
        ]);

        $this->assertFalse($result['saved']);
        $this->assertStringContainsString('outside the timeline', implode(' ', $result['problems']));
    }

    public function test_an_incomplete_sow_is_refused_by_the_message_schema(): void
    {
        $quote = $this->draftQuote('Sow Schema Co');

        $result = $this->generate($quote, renderPdf: false, overrides: ['signatories' => []]);

        $this->assertFalse($result['saved']);
        $this->assertSame('sow_incomplete', $result['reason']);
    }

    public function test_an_accepted_quote_refuses_a_new_sow(): void
    {
        $quote = $this->draftQuote('Sow Committed Co');
        $quote->status = QuoteStatusEnum::ACCEPTED;
        $quote->save();

        $result = $this->generate($quote, renderPdf: false);

        $this->assertFalse($result['saved']);
        $this->assertSame('quote_not_editable', $result['reason']);
    }

    public function test_get_quote_sow_answers_scope_questions_from_the_structured_message(): void
    {
        $quote = $this->draftQuote('Sow Read Co');
        $this->generate($quote, renderPdf: false);

        $tool = new GetQuoteSowTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $inScope = $tool->__invoke(quote_id: $quote->getId(), term: 'android');
        $this->assertTrue($inScope['found']);
        $this->assertNotEmpty($inScope['matches_in_scope']);
        $this->assertSame([], $inScope['matches_out_of_scope']);

        $outOfScope = $tool->__invoke(quote_id: $quote->getId(), term: 'web dashboard');
        $this->assertSame(['Web dashboard'], $outOfScope['matches_out_of_scope']);

        $none = $tool->__invoke(quote_id: $this->draftQuote('No Sow Co')->getId());
        $this->assertFalse($none['found']);
        $this->assertSame('no_sow', $none['reason']);
    }

    public function test_quote_line_ids_are_readable_before_a_sow_exists(): void
    {
        $quote = $this->draftQuote('Sow Line Ids Co');
        $lineIds = $quote->lines->pluck('id')->all();

        $read = new GetQuoteSowTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_id: $quote->getId());
        $this->assertSame($lineIds, array_column($read['quote_lines'], 'quote_line_id'));

        $sent = new SendQuoteAction(quote: $quote, billable: $quote->customer, user: static::$cachedUser)->execute();
        $found = new FindQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(quote_number: (string) $sent->quote_number);
        $this->assertSame($lineIds, array_column($found['lines'], 'quote_line_id'));
    }

    public function test_the_sow_renders_through_the_twig_template_with_quote_and_sow_data(): void
    {
        $quote = $this->draftQuote('Sow Render Co');
        $result = $this->generate($quote, renderPdf: false);
        $message = Message::query()->whereKey($result['sow_message_id'])->firstOrFail();

        TemplateSandbox::validate((string) file_get_contents(resource_path(QuoteSowPdfService::PACKAGED_TEMPLATE)));

        $html = new QuoteSowPdfService($quote->refresh(), $message)->render();

        $this->assertStringContainsString('Statement of Work', $html);
        $this->assertStringContainsString('Sow Render Co', $html);
        $this->assertStringContainsString('Native iOS and Android apps', $html);
        $this->assertStringContainsString('Web dashboard', $html);
        $this->assertStringContainsString('On signature', $html);
        $this->assertStringContainsString('2,000.00', $html);
        $this->assertStringContainsString('Jane Customer', $html);
        $this->assertStringContainsString('Mobile app build', $html);
    }

    public function test_generate_quote_sow_attaches_the_pdf_as_quote_sow(): void
    {
        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }

        $quote = $this->draftQuote('Sow Pdf Co');

        $result = $this->generate($quote);

        $this->assertTrue($result['saved'], json_encode($result));
        $this->assertTrue($result['pdf_generated'], json_encode($result));
        $stored = $quote->refresh()->getFileByName(QuoteSowPdfService::FIELD_NAME);
        $this->assertNotNull($stored);
        $this->assertSame($result['filesystem_id'], $stored->filesystem->getId());
    }

    public function test_attach_quote_file_files_a_sow_and_keeps_other_attachments_beside_it(): void
    {
        $quote = $this->draftQuote('Sow Attach Co');
        $sowFile = $this->createFilesystemRow(url: 'https://cdn.example.test/signed-sow.pdf', name: 'signed-sow.pdf');
        $nda = $this->createFilesystemRow(url: 'https://cdn.example.test/nda.pdf', name: 'nda.pdf');

        $tool = new AttachQuoteFileTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $sow = $tool->__invoke(quote_id: $quote->getId(), kind: 'sow', filesystem_id: $sowFile->getId());
        $attachment = $tool->__invoke(
            quote_id: $quote->getId(),
            kind: 'attachment',
            filesystem_id: $nda->getId(),
            field_name: 'nda'
        );

        $this->assertTrue($sow['file_attached']);
        $this->assertSame(QuoteSowPdfService::FIELD_NAME, $sow['field_name']);
        $this->assertTrue($attachment['file_attached']);
        $this->assertSame($sowFile->getId(), $quote->getFileByName(QuoteSowPdfService::FIELD_NAME)->filesystem->getId());
        $this->assertSame($nda->getId(), $quote->getFileByName('nda')->filesystem->getId());
    }

    public function test_attach_quote_file_refuses_an_unknown_quote_and_a_missing_file(): void
    {
        $tool = new AttachQuoteFileTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $unknown = $tool->__invoke(quote_id: 999999999, file_url: 'https://example.test/sow.pdf');
        $this->assertFalse($unknown['file_attached']);
        $this->assertSame('quote_not_found', $unknown['reason']);

        $noFile = $tool->__invoke(quote_id: $this->draftQuote('Sow No File Co')->getId(), kind: 'sow');
        $this->assertFalse($noFile['file_attached']);
        $this->assertSame('no_file_given', $noFile['reason']);
    }

    /** Keeps the sent-quote path honest: SendQuoteAction must not drop the SOW pointer. */
    public function test_sending_a_quote_keeps_its_sow(): void
    {
        $quote = $this->draftQuote('Sow Sent Co');
        $sow = $this->generate($quote, renderPdf: false);

        $sent = new SendQuoteAction(quote: $quote->refresh(), billable: $quote->customer, user: static::$cachedUser)->execute();

        $this->assertSame($sow['sow_message_id'], $sent->sow_message_id);
        $this->assertTrue(Carbon::now()->isSameDay($sent->sent_at));
    }

    private function draftQuote(string $customerName): Quote
    {
        $this->seedTestOrganization($customerName);

        $created = new CreateQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(customer_name: $customerName, lines: [
                ['description' => 'Discovery workshop', 'unit_price' => 1000.0],
                ['description' => 'Mobile app build', 'unit_price' => 3000.0],
            ]);

        $this->assertTrue($created['created'], json_encode($created));

        return Quote::getById($created['quote_id']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function generate(Quote $quote, bool $renderPdf = true, array $overrides = []): array
    {
        $lineIds = $quote->lines->pluck('id')->all();

        $sow = array_merge([
            'summary' => 'Build the customer mobile app.',
            'scope' => ['Native iOS and Android apps', 'Discovery workshop with the product team'],
            'out_of_scope' => ['Web dashboard'],
            'assumptions' => ['The customer provides API access'],
            'deliverables' => [
                ['name' => 'Discovery report', 'acceptance_criteria' => 'Approved by the customer.', 'quote_line_ids' => [$lineIds[0]]],
                ['name' => 'Mobile apps in the stores', 'acceptance_criteria' => 'Published.', 'quote_line_ids' => [$lineIds[1]]],
            ],
            'timeline_start' => '2026-06-15',
            'timeline_end' => '2026-08-31',
            'payment_schedule' => [
                ['label' => 'Deposit', 'when' => 'on_signature', 'percent' => 50],
                ['label' => 'Balance', 'when' => 'date', 'date' => '2026-07-15', 'percent' => 50],
            ],
            'signatories' => [
                ['name' => 'Jane Customer', 'role' => 'CEO, customer'],
                ['name' => 'Max Provider', 'role' => 'CEO, provider'],
            ],
            'change_process' => 'Changes go through a written change order.',
        ], $overrides);

        return new GenerateQuoteSowTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                quote_id: $quote->getId(),
                summary: $sow['summary'],
                scope: $sow['scope'],
                deliverables: $sow['deliverables'],
                timeline_start: $sow['timeline_start'],
                timeline_end: $sow['timeline_end'],
                payment_schedule: $sow['payment_schedule'],
                signatories: $sow['signatories'],
                out_of_scope: $sow['out_of_scope'],
                assumptions: $sow['assumptions'],
                change_process: $sow['change_process'],
                render_pdf: $renderPdf,
            );
    }
}
