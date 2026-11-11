<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\CreateFilesystemMapperAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper as FilesystemMapperData;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Banking\Services\BankStatementCsvParserService;
use Kanvas\Scribe\Banking\Services\BankStatementMapperResolverService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

/**
 * Stores a statement mapping proposed for a layout no template knows (the agent drafts it from the header
 * row and the person confirms it), as the company's own FilesystemMapper, and pins it on the account.
 * From then on that bank's files parse like any seeded one.
 */
class SaveBankStatementMappingAction
{
    /** Every key a statement mapping may set; anything else is a typo the parser would silently ignore. */
    private const array ALLOWED_KEYS = [
        'date',
        'amount',
        'debit',
        'credit',
        'direction',
        'running_balance',
        'description',
        'reference',
        'counterparty',
        'date_format',
        'decimal_separator',
    ];

    /**
     * @param list<string> $header
     * @param array<string, mixed> $mapping
     */
    public function __construct(
        public readonly BankAccount $bankAccount,
        public readonly array $header,
        public readonly array $mapping,
        public readonly UserInterface $user,
        protected readonly BankStatementCsvParserService $parser = new BankStatementCsvParserService(),
    ) {
    }

    public function execute(): FilesystemMapper
    {
        $this->assertUsable();

        $mapper = new CreateFilesystemMapperAction(
            new FilesystemMapperData(
                app: $this->bankAccount->app,
                branch: $this->bankAccount->company->defaultBranch,
                user: $this->user,
                systemModule: SystemModulesRepository::getByModelName(BankTransaction::class, $this->bankAccount->app),
                name: 'Bank statement — ' . $this->bankAccount->account_name . ' · ' . substr(sha1(json_encode($this->mapping)), 0, 8),
                header: $this->header,
                mapping: $this->mapping,
                configuration: ['statement' => ['bank_account_id' => $this->bankAccount->getId()]],
                description: 'Proposed from the header of a statement export for ' . $this->bankAccount->account_name . '.',
            ),
        )->execute();

        new BankStatementMapperResolverService($this->parser)->pin($this->bankAccount, $mapper);

        return $mapper;
    }

    private function assertUsable(): void
    {
        $unknownKeys = array_diff(array_keys($this->mapping), self::ALLOWED_KEYS);
        if ($unknownKeys !== []) {
            throw new ValidationException(
                'Unknown mapping key(s): ' . implode(', ', $unknownKeys) . '. Allowed: ' . implode(', ', self::ALLOWED_KEYS) . '.'
            );
        }

        if (! isset($this->mapping['date'])) {
            throw new ValidationException('The mapping needs a "date" column.');
        }

        if (! isset($this->mapping['amount']) && ! isset($this->mapping['debit']) && ! isset($this->mapping['credit'])) {
            throw new ValidationException('The mapping needs either an "amount" column or "debit"/"credit" columns.');
        }

        $header = array_map(fn ($column): string => Str::lowerTrim((string) $column), $this->header);
        $missing = array_filter(
            $this->parser->referencedColumns($this->mapping),
            fn (string $column): bool => ! in_array(Str::lowerTrim($column), $header, true),
        );

        if ($missing !== []) {
            throw new ValidationException(
                'The mapping reads column(s) that are not in the file header: ' . implode(', ', $missing) . '.'
            );
        }
    }
}
