<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Services;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Collection;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Imports\Actions\CreateMapperFromTemplateAction;
use Kanvas\Imports\DataTransferObject\MapperFromTemplate;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

/**
 * Picks the FilesystemMapper that reads a statement CSV, in this order:
 *
 *   1. the mapper pinned on the bank account (the last one that worked for it)
 *   2. any of the company's statement mappers whose columns are all in the file
 *   3. a shipped template whose columns are all in the file — copied into a company mapper first
 *
 * Whatever wins is pinned on the account, so the next statement for it skips the search. No match
 * returns null and the caller hands the header back for a mapping to be proposed.
 */
class BankStatementMapperResolverService
{
    public const string PINNED_MAPPER_KEY = 'statement_mapper_id';

    public function __construct(
        protected readonly BankStatementCsvParserService $parser = new BankStatementCsvParserService(),
    ) {
    }

    public function resolve(BankAccount $account, string $contents, UserInterface $user): ?FilesystemMapper
    {
        $mapper = $this->pinned($account, $contents)
            ?? $this->companyMapper($account, $contents)
            ?? $this->fromTemplate($account, $contents, $user);

        if ($mapper !== null) {
            $this->pin($account, $mapper);
        }

        return $mapper;
    }

    public function pin(BankAccount $account, FilesystemMapper $mapper): void
    {
        $metadata = $account->metadata ?? [];

        if (($metadata[self::PINNED_MAPPER_KEY] ?? null) === $mapper->getId()) {
            return;
        }

        $metadata[self::PINNED_MAPPER_KEY] = $mapper->getId();
        $account->metadata = $metadata;
        $account->save();
    }

    private function pinned(BankAccount $account, string $contents): ?FilesystemMapper
    {
        $mapperId = $account->metadata[self::PINNED_MAPPER_KEY] ?? null;

        if ($mapperId === null) {
            return null;
        }

        $mapper = $this->statementMappers($account)->firstWhere('id', (int) $mapperId);

        return $mapper !== null && $this->parser->matches($contents, (array) $mapper->mapping) ? $mapper : null;
    }

    private function companyMapper(BankAccount $account, string $contents): ?FilesystemMapper
    {
        return $this->statementMappers($account)
            ->first(fn (FilesystemMapper $mapper): bool => $this->parser->matches($contents, (array) $mapper->mapping));
    }

    private function fromTemplate(BankAccount $account, string $contents, UserInterface $user): ?FilesystemMapper
    {
        foreach (ImportTemplateEnum::bankStatementTemplates() as $templateCase) {
            $template = $templateCase->template();

            if (! $this->parser->matches($contents, $template->mapping)) {
                continue;
            }

            return new CreateMapperFromTemplateAction(
                new MapperFromTemplate(
                    template: $template,
                    app: $account->app,
                    branch: $account->company->defaultBranch,
                    user: $user,
                ),
            )->execute();
        }

        return null;
    }

    /**
     * @return Collection<int, FilesystemMapper>
     */
    private function statementMappers(BankAccount $account): Collection
    {
        $systemModule = SystemModulesRepository::getByModelName(BankTransaction::class, $account->app);

        return FilesystemMapper::query()
            ->where('apps_id', $account->apps_id)
            ->where('companies_id', $account->companies_id)
            ->where('system_modules_id', $systemModule->getId())
            ->where('is_deleted', 0)
            ->orderByDesc('id')
            ->get();
    }
}
