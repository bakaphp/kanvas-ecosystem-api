<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Actions\CreateFiscalDocumentAction;
use Kanvas\Scribe\ElectronicInvoicing\Actions\RecordFiscalEventAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalEventTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalSubmissionStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentSubmission;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Throwable;

/**
 * Moves test-environment `regional_compliance.do_ecf` blocks onto fiscal rows: one fiscal row plus, when the
 * block was sent, one submission (secuenciaUtilizada unknown, so `authority_number_consumed` stays null).
 * The post-allocation keys are then stripped from the JSON; the draft settings stay. Idempotent: a document
 * that already has a row for its e-NCF is skipped. Production blocks are never touched — the preflight
 * command fails the deploy on them.
 */
class BackfillFiscalDocumentsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:dr-fiscal-backfill
                            {--company=* : Only these company ids (default: every company)}
                            {--dry-run : Report what would be moved without writing}';

    protected $description = 'Dominican Republic legacy data: backfill fiscal_documents from test-environment regional_compliance.do_ecf blocks.';

    private ?int $currentAppId = null;

    public function handle(): int
    {
        $stats = ['created' => 0, 'skipped' => 0, 'production' => 0, 'failed' => 0];

        $companyIds = array_values(array_map('intval', (array) $this->option('company')));

        foreach ([Invoice::class, Bill::class, Expense::class] as $model) {
            $model::query()
                ->when($companyIds !== [], fn ($query) => $query->whereIn('companies_id', $companyIds))
                ->whereNotNull('regional_compliance->' . self::legacyKey() . '->encf')
                ->orderBy('apps_id')
                ->chunkById(200, function ($documents) use (&$stats): void {
                    foreach ($documents as $document) {
                        try {
                            $stats[$this->backfill($document)]++;
                        } catch (Throwable $e) {
                            report($e);
                            $stats['failed']++;
                            $this->warn(class_basename($document) . " {$document->getId()}: {$e->getMessage()}");
                        }
                    }
                });
        }

        $this->table(array_keys($stats), [array_values($stats)]);

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function backfill(Invoice|Bill|Expense $document): string
    {
        if ($this->currentAppId !== (int) $document->apps_id) {
            $app = Apps::find((int) $document->apps_id);
            if ($app !== null) {
                $this->overwriteAppService($app);
            }
            $this->currentAppId = (int) $document->apps_id;
        }

        $company = Companies::getById((int) $document->companies_id);
        $environment = EcfEnvironmentEnum::forCompany($company);

        if ($environment->isProduction()) {
            return 'production';
        }

        $block = EcfCompliance::rawBlock($document, self::legacyKey());
        $encf = Str::trimToNull(strtoupper((string) ($block['encf'] ?? '')));

        if ($encf === null || FiscalDocument::query()->forEntity($document)->where('number', $encf)->exists()) {
            return 'skipped';
        }

        if ($this->option('dry-run')) {
            return 'created';
        }

        DB::connection('accounting')->transaction(function () use ($document, $company, $environment, $block, $encf): void {
            $received = ($block['direction'] ?? null) === FiscalDirectionEnum::RECEIVED->value && $document instanceof Bill;

            $fiscal = new CreateFiscalDocumentAction(
                entity: $document,
                direction: $received ? FiscalDirectionEnum::RECEIVED : FiscalDirectionEnum::ISSUED,
                attributes: $received
                    ? $this->receivedAttributes(
                        $document,
                        $environment,
                        $block,
                        $encf
                    )
                    : $this->issuedAttributes(
                        $document,
                        $company,
                        $environment,
                        $block,
                        $encf
                    ),
            )->execute();

            if (! $received && self::wasSent($block)) {
                $this->recordSubmission($fiscal, $block);
            }

            if ($received && is_array($block[ReceivedEcfCompliance::COMMERCIAL_RESPONSE] ?? null)) {
                ReceivedEcfCompliance::recordCommercialResponse($document, $block[ReceivedEcfCompliance::COMMERCIAL_RESPONSE]);
            }

            $compliance = $document->regional_compliance ?? [];
            $compliance[self::legacyKey()] = EcfCompliance::draftSettings($document, self::legacyKey());
            $document->regional_compliance = $compliance;
            $document->saveQuietly();
        });

        return 'created';
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function issuedAttributes(
        Invoice|Bill|Expense $document,
        Companies $company,
        EcfEnvironmentEnum $environment,
        array $block,
        string $encf
    ): array {
        $status = $this->status($block);
        $issuer = DrFiscalRegime::issuerTaxIdFor($company);
        $issuedAt = $this->issuedAt($document, $block);
        $wasSent = self::wasSent($block);

        return [
            'country' => DrFiscalRegime::COUNTRY,
            'provider' => $block['provider'] ?? null,
            'environment' => $environment->value,
            'schema_version' => DrFiscalRegime::SCHEMA_VERSION,
            'document_type' => (string) ($block['type'] ?? substr($encf, 1, 2)),
            'series' => substr($encf, 0, 3),
            'number' => $encf,
            'issuer_tax_id' => $issuer,
            'identity_key' => DrFiscalRegime::identityKey($issuer ?? 'company-' . $company->getId(), $encf),
            'authority_id' => $status->isAccepted() ? $encf : null,
            'authority_seal' => $block['security_code'] ?? null,
            'status' => $status,
            'authority_status' => $block['status'] ?? null,
            'signed_at' => DateHelper::tryParseCarbon($block['signed_at'] ?? null),
            'issued_at' => $issuedAt,
            'frozen_at' => $wasSent ? Carbon::now() : null,
            'retain_until' => $issuedAt->copy()->addYears(DrFiscalRegime::RETENTION_YEARS),
            'regime_data' => [
                ...EcfCompliance::draftSettings($document),
                'expires_at' => $block['expires_at'] ?? null,
                'issue_date' => $block['issue_date'] ?? $issuedAt->toDateString(),
                'references' => $block['references'] ?? null,
                'backfilled' => true,
            ],
            'authority_data' => array_filter([
                'messages' => $block['messages'] ?? null,
                'signed_at' => $block['signed_at'] ?? null,
                'qr_url' => $block['qr_url'] ?? null,
                'qr_legends' => $block['qr_legends'] ?? null,
                'provider_ref' => $block['provider_ref'] ?? null,
                'poll_attempts' => $block['poll_attempts'] ?? null,
                'poll_exhausted' => $block['poll_exhausted'] ?? null,
                'action_required' => $block['action_required'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function receivedAttributes(
        Bill $bill,
        EcfEnvironmentEnum $environment,
        array $block,
        string $encf
    ): array {
        $vendor = (string) ($block['vendor_rnc'] ?? $bill->vendor_tax_id ?? '');
        $issuedAt = $this->issuedAt($bill, $block);

        return [
            'country' => DrFiscalRegime::COUNTRY,
            'provider' => $block['provider'] ?? null,
            'environment' => $environment->value,
            'schema_version' => DrFiscalRegime::SCHEMA_VERSION,
            'document_type' => (string) ($block['type'] ?? substr($encf, 1, 2)),
            'series' => substr($encf, 0, 3),
            'number' => $encf,
            'issuer_tax_id' => Str::trimToNull($vendor),
            'identity_key' => DrFiscalRegime::identityKey($vendor, $encf),
            'authority_id' => $encf,
            'authority_seal' => $block['security_code'] ?? null,
            'status' => FiscalDocumentStatusEnum::ACCEPTED,
            'signed_at' => DateHelper::tryParseCarbon($block['signed_at'] ?? null),
            'issued_at' => $issuedAt,
            'retain_until' => $issuedAt->copy()->addYears(DrFiscalRegime::RETENTION_YEARS),
            'regime_data' => [
                'stamp_url' => $block['stamp_url'] ?? null,
                'references' => $block['references'] ?? null,
                'backfilled' => true,
            ],
            'authority_data' => ['signed_at' => $block['signed_at'] ?? null],
        ];
    }

    /**
     * @param array<string, mixed> $block
     */
    private function recordSubmission(FiscalDocument $fiscal, array $block): void
    {
        $answer = EcfStatusEnum::tryFrom((string) ($block['status'] ?? ''));

        FiscalDocumentSubmission::create([
            'apps_id' => $fiscal->apps_id,
            'companies_id' => $fiscal->companies_id,
            'fiscal_document_id' => $fiscal->getKey(),
            'attempt' => 1,
            'channel' => 'sync',
            'status' => $answer !== null ? FiscalSubmissionStatusEnum::fromAnswer($answer) : FiscalSubmissionStatusEnum::UNKNOWN,
            'provider' => $fiscal->provider,
            'provider_ref' => $block['provider_ref'] ?? null,
            'messages' => $block['messages'] ?? [],
            'authority_number_consumed' => null,
            'sent_at' => DateHelper::tryParseCarbon($block['submitted_at'] ?? null),
        ]);

        new RecordFiscalEventAction(
            document: $fiscal,
            type: FiscalEventTypeEnum::AUTHORITY_ANSWER,
            direction: 'received',
            status: $fiscal->status->value,
            dedupeKey: 'backfill:' . $fiscal->uuid,
            payload: ['backfilled' => true, 'status' => $block['status'] ?? null, 'messages' => $block['messages'] ?? []],
            attributes: ['applied_at' => Carbon::now(), 'effects_completed_at' => Carbon::now()],
        )->execute();
    }

    /**
     * @param array<string, mixed> $block
     */
    private function status(array $block): FiscalDocumentStatusEnum
    {
        $answer = EcfStatusEnum::tryFrom((string) ($block['status'] ?? ''));

        return match (true) {
            $answer === null, $answer === EcfStatusEnum::WAITING && ! self::wasSent($block) => FiscalDocumentStatusEnum::DRAFT,
            default => FiscalDocumentStatusEnum::fromAnswer($answer),
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private function issuedAt(Invoice|Bill|Expense $document, array $block): Carbon
    {
        return DateHelper::tryParseCarbon($block['issue_date'] ?? null)
            ?? ($document instanceof Invoice ? $document->issued_date?->copy() : null)
            ?? Carbon::parse($document->created_at);
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function wasSent(array $block): bool
    {
        return isset($block['provider_ref']) || isset($block['submitted_at']);
    }

    private static function legacyKey(): string
    {
        return EcfCompliance::keyFor(DrFiscalRegime::COUNTRY);
    }
}
