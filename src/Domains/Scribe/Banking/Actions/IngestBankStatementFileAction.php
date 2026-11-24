<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Scribe\Banking\Contracts\BankStatementExtractorInterface;
use Kanvas\Scribe\Banking\DataTransferObject\BankStatementIngestResult;
use Kanvas\Scribe\Banking\DataTransferObject\ParsedBankStatement;
use Kanvas\Scribe\Banking\Enums\BankStatementRejectionEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementSourceEnum;
use Kanvas\Scribe\Banking\Exceptions\BankStatementRejectedException;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Services\BankStatementCoverageService;
use Kanvas\Scribe\Banking\Services\BankStatementCsvParserService;
use Kanvas\Scribe\Banking\Services\BankStatementFileReaderService;
use Kanvas\Scribe\Banking\Services\BankStatementMapperResolverService;
use Kanvas\Scribe\Banking\Services\GeminiBankStatementExtractorService;

/**
 * A stored statement file → the ingest action. Every file entry point (accounting inbox, a file handed
 * to the agent, a webhook upload) comes through here, so the agent never parses rows itself.
 *
 * The bytes decide the parser, not the file name: a PDF or a photo goes to the extractor, anything else
 * is read as CSV through the account's FilesystemMapper. A CSV carries no account header, so it needs
 * the account — given, or the company's only active one.
 */
class IngestBankStatementFileAction
{
    private const array EXTRACTABLE_PREFIXES = ['application/pdf', 'image/'];

    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Filesystem $file,
        public readonly ?BankAccount $bankAccount = null,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): BankStatementIngestResult
    {
        $bytes = $this->reader()->read($this->file);
        $mimeType = FilesystemServices::detectMimeTypeFromBytes($bytes);

        if ($this->isExtractable($mimeType)) {
            return $this->ingest($this->extractor()->extract($bytes), BankStatementSourceEnum::PDF, $this->bankAccount);
        }

        $account = $this->bankAccount ?? $this->onlyActiveAccount();

        if ($account === null) {
            return $this->ingest(
                new ParsedBankStatement(lines: []),
                BankStatementSourceEnum::CSV,
                null,
                new BankStatementRejectedException(
                    BankStatementRejectionEnum::ACCOUNT_NOT_FOUND,
                    'A CSV statement does not say which account it belongs to, and this company has more than one. '
                    . 'Say which bank account it is for.',
                ),
            );
        }

        $parser = new BankStatementCsvParserService();
        $mapper = new BankStatementMapperResolverService($parser)->resolve(
            $account,
            $bytes,
            $this->user ?? $this->company->user,
        );

        if ($mapper === null) {
            $peek = $parser->peek($bytes);

            return BankStatementIngestResult::unknownLayout($peek['header'], $peek['sample_rows']);
        }

        try {
            $statement = $parser->parse($bytes, (array) $mapper->mapping);
        } catch (BankStatementRejectedException $e) {
            return $this->ingest(
                new ParsedBankStatement(lines: []),
                BankStatementSourceEnum::CSV,
                $account,
                $e
            );
        }

        return $this->ingest($statement, BankStatementSourceEnum::CSV, $account);
    }

    /**
     * A parse failure is recorded as a rejected statement like any other rule failure, so the agent and
     * the audit see it the same way.
     */
    private function ingest(
        ParsedBankStatement $statement,
        BankStatementSourceEnum $source,
        ?BankAccount $account,
        ?BankStatementRejectedException $parseFailure = null
    ): BankStatementIngestResult {
        return new IngestBankStatementAction(
            app: $this->app,
            company: $this->company,
            statement: $statement,
            source: $source,
            bankAccount: $account,
            file: $this->file,
            user: $this->user,
            parseFailure: $parseFailure,
        )->execute();
    }

    private function isExtractable(string $mimeType): bool
    {
        foreach (self::EXTRACTABLE_PREFIXES as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function onlyActiveAccount(): ?BankAccount
    {
        $accounts = new BankStatementCoverageService()->activeAccounts($this->app, $this->company);

        return $accounts->count() === 1 ? $accounts->first() : null;
    }

    private function reader(): BankStatementFileReaderService
    {
        return app(BankStatementFileReaderService::class);
    }

    private function extractor(): BankStatementExtractorInterface
    {
        return app()->bound(BankStatementExtractorInterface::class)
            ? app(BankStatementExtractorInterface::class)
            : new GeminiBankStatementExtractorService();
    }
}
