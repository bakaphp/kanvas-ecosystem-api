<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Illuminate\Http\Request;
use Kanvas\Scribe\Banking\Actions\IngestBankStatementFileAction;
use Kanvas\Scribe\Banking\Actions\IngestBankStatementFromInboxAction;
use Kanvas\Scribe\Banking\Enums\BankStatementIngestOutcomeEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementRejectionEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementSourceEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankStatement;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Banking\Webhooks\BankStatementWebhookJob;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Laravel\Ai\StructuredAnonymousAgent;

final class BankStatementPdfAndWebhookTest extends BankStatementTestCase
{
    private const string FAKE_PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF";

    private BankAccount $account;

    protected function afterScribeSetUp(): void
    {
        $this->account = $this->statementAccount('4321', 'DOP', 'Popular DOP');
    }

    public function testAPdfStatementIsExtractedMatchedToItsAccountAndPosted(): void
    {
        $this->fakeStatementFileContents(self::FAKE_PDF);
        StructuredAnonymousAgent::fake([$this->extraction()]);

        $result = new IngestBankStatementFileAction(
            app: $this->kanvasApp,
            company: $this->company,
            file: $this->createFilesystemRow(),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BankStatementIngestOutcomeEnum::POSTED, $result->outcome, (string) $result->reason);
        $this->assertSame(BankStatementSourceEnum::PDF, $result->statement->source);
        $this->assertSame($this->account->getId(), $result->statement->bank_account_id, 'Matched by the header last 4 + currency.');
        $this->assertSame('2026-06-30', $result->statement->period_end->toDateString());
        $this->assertSame(2, $result->newRows);
    }

    public function testAMisreadPdfLandsNothing(): void
    {
        $this->fakeStatementFileContents(self::FAKE_PDF);
        StructuredAnonymousAgent::fake([$this->extraction(closing: 9_999.00)]);

        $result = new IngestBankStatementFileAction(
            app: $this->kanvasApp,
            company: $this->company,
            file: $this->createFilesystemRow(),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BankStatementRejectionEnum::BALANCE_MISMATCH, $result->reasonCode);
        $this->assertSame(0, BankTransaction::query()->where('bank_account_id', $this->account->getId())->count());
    }

    public function testTheInboxRouteLinksThePdfLogToThePostedStatement(): void
    {
        $this->fakeStatementFileContents(self::FAKE_PDF);
        StructuredAnonymousAgent::fake([$this->extraction()]);
        $pdf = $this->createFilesystemRow();

        $log = new PdfIngestLog();
        $log->apps_id = $this->kanvasApp->getId();
        $log->companies_id = $this->company->getId();
        $log->filesystem_id = $pdf->getId();
        $log->document_type = PdfIngestDocumentTypeEnum::BANK_STATEMENT;
        $log->confidence = 0.95;
        $log->status = PdfIngestStatusEnum::PENDING;
        $log->save();

        new IngestBankStatementFromInboxAction(
            $log,
            new PdfIngestInput(app: $this->kanvasApp, company: $this->company, pdf: $pdf),
            static::$cachedUser,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame('bank_statement', $log->linked_entity_type);
        $this->assertNotNull(BankStatement::query()->find($log->linked_entity_id));
    }

    public function testTheWebhookLandsJsonRowsForThePinnedAccount(): void
    {
        $receiver = $this->receiver(['shared_secret' => 's3cret', 'bank_account_id' => $this->account->getId()]);

        $result = $this->runWebhook($receiver, [
            'opening_balance' => 5_000,
            'closing_balance' => 5_975,
            'rows' => [
                ['date' => '2026-06-02', 'credit' => 1000, 'description' => 'Deposito', 'reference' => 'W-1'],
                ['date' => '2026-06-03', 'debit' => 25, 'description' => 'Comision', 'reference' => 'W-2'],
            ],
        ]);

        $this->assertSame('posted', $result['outcome']);
        $this->assertSame(2, $result['new_rows']);
        $this->assertSame(
            BankStatementSourceEnum::WEBHOOK,
            BankStatement::query()->find($result['bank_statement_id'])->source,
        );
    }

    public function testTheWebhookRejectsAnAccountOfAnotherCompanyAndUnsignedRequests(): void
    {
        $receiver = $this->receiver(['shared_secret' => 's3cret']);

        $result = $this->runWebhook($receiver, ['bank_account_id' => 999_999_999, 'rows' => []]);
        $this->assertSame('account_not_found', $result['reason_code']);

        $request = Request::create('/receiver/x', 'POST');
        $this->assertFalse(BankStatementWebhookJob::authenticateRequest($request, $receiver));

        $request->headers->set('X-Webhook-Secret', 'wrong');
        $this->assertFalse(BankStatementWebhookJob::authenticateRequest($request, $receiver));

        $request->headers->set('X-Webhook-Secret', 's3cret');
        $this->assertTrue(BankStatementWebhookJob::authenticateRequest($request, $receiver));

        $this->assertFalse(
            BankStatementWebhookJob::authenticateRequest($request, $this->receiver([])),
            'A receiver with no secret configured refuses everything.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function extraction(float $closing = 5_975.00): array
    {
        return [
            'account_number' => '****4321',
            'currency' => 'DOP',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'opening_balance' => 5_000.00,
            'closing_balance' => $closing,
            'rows' => [
                ['date' => '2026-06-02', 'description' => 'DEPOSITO', 'reference' => 'P-1', 'debit' => 0, 'credit' => 1_000.00, 'running_balance' => 6_000.00],
                ['date' => '2026-06-03', 'description' => 'COMISION', 'reference' => 'P-2', 'debit' => 25.00, 'credit' => 0, 'running_balance' => 5_975.00],
            ],
        ];
    }

    private function receiver(array $configuration): ReceiverWebhook
    {
        $action = WorkflowAction::firstOrCreate(
            ['model_name' => BankStatementWebhookJob::class],
            ['name' => 'BankStatementWebhookJob'],
        );

        return ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user(static::$cachedUser->getId())
            ->company($this->company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => $configuration,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runWebhook(ReceiverWebhook $receiver, array $payload): array
    {
        $call = new ProcessWebhookAttemptAction(
            $receiver,
            Request::create('https://localhost/v1/receiver/' . $receiver->uuid, 'POST', $payload),
        )->execute();

        return new BankStatementWebhookJob($call)->execute();
    }
}
