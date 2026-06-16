<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Local-dev tool: download a PDF from a URL and run the FULL live Scribe ingest pipeline against
 * it — real Gemini classification, real Propose action, real GL JE post. Prints the resulting
 * PdfIngestLog row + whatever entity (Expense / Bill) got created.
 *
 *   php artisan scribe:test-pdf-ingest https://example.com/invoice.pdf
 *   php artisan scribe:test-pdf-ingest https://example.com/invoice.pdf --app=1 --company=11569
 *
 * Required app config: ConfigurationEnum::GEMINI_KEY must be set on the app (per-tenant). The
 * command checks this up front and tells you how to set it.
 *
 * Per the queue-worker memory rule, calls overwriteAppService($app) before running.
 */
class TestPdfIngestCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:test-pdf-ingest
                            {url : Publicly fetchable URL of the invoice / receipt PDF}
                            {--app= : Apps id (defaults to first non-deleted app)}
                            {--company= : Companies id (defaults to first company for the app)}
                            {--user= : Users id (defaults to first user; needs to be a member of the company)}
                            {--from-email= : Optional from_email hint (helps the classifier infer document type)}
                            {--subject= : Optional subject hint}';

    protected $description = 'Run a real PDF through the live Scribe ingest pipeline (Gemini classify + Propose + GL post).';

    public function handle(): int
    {
        $url = trim((string) $this->argument('url'));
        if ($url === '') {
            $this->error('URL is required.');

            return self::FAILURE;
        }

        $app = $this->resolveApp();
        if ($app === null) {
            return self::FAILURE;
        }

        $this->overwriteAppService($app);

        $company = $this->resolveCompany($app);
        $user = $this->resolveUser($company);
        if ($company === null || $user === null) {
            return self::FAILURE;
        }

        $this->info("Tenant context — app={$app->getId()} company={$company->getId()} user={$user->getId()}");

        if (! $this->geminiConfigured($app)) {
            return self::FAILURE;
        }

        $pdf = $this->createFilesystemRowFromUrl($url, $app, $company, $user);
        $this->info("Filesystem row created — id={$pdf->getId()} uuid={$pdf->uuid}");

        $this->info('Calling ProcessAccountingPdfAction with the LIVE Gemini classifier…');

        try {
            $log = new ProcessAccountingPdfAction(
                input: new PdfIngestInput(
                    app: $app,
                    company: $company,
                    pdf: $pdf,
                    messageId: 'cli-' . Str::uuid()->toString(),
                    fromEmail: $this->option('from-email'),
                    subject: $this->option('subject'),
                ),
                user: $user,
            )->execute();
        } catch (Throwable $e) {
            $this->error("Pipeline threw: {$e->getMessage()}");
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }

        $this->renderResult($log);

        return $log->status === PdfIngestStatusEnum::FAILED ? self::FAILURE : self::SUCCESS;
    }

    private function resolveApp(): ?Apps
    {
        $appId = $this->option('app');
        if ($appId !== null) {
            $app = Apps::query()->where('id', (int) $appId)->where('is_deleted', false)->first();
            if ($app === null) {
                $this->error("No app with id={$appId}.");

                return null;
            }

            return $app;
        }

        $app = Apps::query()->where('is_deleted', false)->orderBy('id')->first();
        if ($app === null) {
            $this->error('No apps in this DB — pass --app=<id> or seed an app first.');

            return null;
        }

        return $app;
    }

    private function resolveCompany(Apps $app): ?Companies
    {
        $companyId = $this->option('company');
        if ($companyId !== null) {
            $company = Companies::query()->where('id', (int) $companyId)->where('is_deleted', false)->first();
            if ($company === null) {
                $this->error("No company with id={$companyId}.");

                return null;
            }

            return $company;
        }

        $companyId = Companies::query()
            ->join('users_associated_apps', 'users_associated_apps.companies_id', '=', 'companies.id')
            ->where('users_associated_apps.apps_id', $app->getId())
            ->where('companies.is_deleted', false)
            ->orderBy('companies.id')
            ->value('companies.id');
        if ($companyId === null) {
            $this->error("No company associated with app={$app->getId()} — pass --company=<id>.");

            return null;
        }

        return Companies::query()->where('id', $companyId)->first();
    }

    private function resolveUser(?Companies $company): ?Users
    {
        if ($company === null) {
            return null;
        }
        $userId = $this->option('user');
        if ($userId !== null) {
            $user = Users::query()->where('id', (int) $userId)->first();
            if ($user === null) {
                $this->error("No user with id={$userId}.");

                return null;
            }

            return $user;
        }

        $userId = Users::query()
            ->join('users_associated_apps', 'users_associated_apps.users_id', '=', 'users.id')
            ->where('users_associated_apps.companies_id', $company->getId())
            ->orderBy('users.id')
            ->value('users.id');
        if ($userId === null) {
            $this->error("No user associated with company={$company->getId()} — pass --user=<id>.");

            return null;
        }

        return Users::query()->where('id', $userId)->first();
    }

    private function geminiConfigured(Apps $app): bool
    {
        $key = $app->get(ConfigurationEnum::GEMINI_KEY->value);
        if (! is_string($key) || $key === '') {
            $this->error('Gemini API key not configured for this app.');
            $this->line('Set it with:');
            $this->line(
                "  app(Apps::class)->set('"
                . ConfigurationEnum::GEMINI_KEY->value
                . "', 'YOUR_KEY')"
            );
            $this->line('(or via the admin UI / config seeder).');

            return false;
        }

        return true;
    }

    private function createFilesystemRowFromUrl(string $url, Apps $app, Companies $company, Users $user): Filesystem
    {
        $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'invoice.pdf');
        if (! str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        $row = new Filesystem();
        $row->apps_id = $app->getId();
        $row->companies_id = $company->getId();
        $row->users_id = $user->getId();
        $row->name = $filename;
        $row->path = 'scribe-test/' . Carbon::now()->format('YmdHisu') . '/' . $filename;
        $row->url = $url;
        $row->size = '0';
        $row->file_type = 'pdf';
        $row->save();

        return $row;
    }

    private function renderResult(PdfIngestLog $log): void
    {
        $this->line('');
        $this->info('── PdfIngestLog ──');
        $this->table(['field', 'value'], [
            ['id', $log->id],
            ['status', $log->status->value],
            ['document_type', $log->document_type?->value ?? '—'],
            ['confidence', $log->confidence !== null ? number_format((float) $log->confidence, 3) : '—'],
            ['reasoning', $this->truncate($log->reasoning)],
            ['linked_entity_type', $log->linked_entity_type ?? '—'],
            ['linked_entity_id', $log->linked_entity_id ?? '—'],
            ['rejected_reason', $this->truncate($log->rejected_reason)],
            ['content_sha256', $log->content_sha256 ?? '—'],
        ]);

        if ($log->extracted_payload !== null) {
            $this->line('');
            $this->info('── Extracted payload (from Gemini) ──');
            $this->line(json_encode($log->extracted_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ($log->linked_entity_id !== null && $log->linked_entity_type !== null) {
            $this->line('');
            $this->info('── Linked entity (' . $log->linked_entity_type . ' ' . $log->linked_entity_id . ') ──');
            $this->renderLinkedEntity($log->linked_entity_type, (int) $log->linked_entity_id);
        }
    }

    private function renderLinkedEntity(string $type, int $id): void
    {
        $class = match ($type) {
            'expense' => Expense::class,
            'bill' => Bill::class,
            default => null,
        };
        if ($class === null) {
            $this->line('(no inspector for type=' . $type . ')');

            return;
        }

        $row = $class::query()->where('id', $id)->first();
        if ($row === null) {
            $this->line("(could not load {$type} #{$id})");

            return;
        }

        $this->line(json_encode(
            $row->only(array_diff(array_keys($row->getAttributes()), ['metadata'])),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function truncate(?string $s, int $max = 240): string
    {
        if ($s === null || $s === '') {
            return '—';
        }

        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }
}
