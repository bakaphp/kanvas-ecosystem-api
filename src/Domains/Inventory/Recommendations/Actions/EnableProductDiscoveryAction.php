<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Recommendations\Enrichment\Actions\BackfillProductEnrichmentAction;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\DiscoveryIndexAdapterResolverService;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryResolver;
use Throwable;

/**
 * The single setup entry point behind the command, the mutation and the agent tool. It detects the
 * engine the app already runs on rather than choosing one, prepares that engine's index, and sets
 * the two flags at the requested scope. A flag left null is not touched.
 */
class EnableProductDiscoveryAction
{
    public const string INDEX_SKIPPED = 'skipped';
    public const string INDEX_UNCHANGED = 'unchanged';
    public const string INDEX_PREPARED = 'prepared';
    public const string INDEX_REFUSED = 'refused';
    public const string INDEX_FAILED = 'failed';

    public function __construct(
        private readonly Apps $app,
        private readonly ?Companies $company = null,
        private readonly ?bool $enrichment = null,
        private readonly ?bool $discovery = null,
        private ?DiscoveryIndexAdapterResolverService $adapters = null,
    ) {
    }

    /**
     * A company id from outside is only trusted once it is shown to belong to the app.
     */
    public static function companyFor(AppInterface $app, int $companyId): Companies
    {
        /** @var Companies|null $company */
        $company = AppsRepository::getActiveCompaniesForAppBuilder($app)
            ->where('companies.id', $companyId)
            ->first();

        if ($company === null) {
            throw new ValidationException('No company ' . $companyId . ' is available on this app.');
        }

        return $company;
    }

    /**
     * @return array{
     *     scope: string,
     *     engine: string,
     *     index: array{status: string, index: ?string, changes: list<string>, message: ?string},
     *     enrichment: ?bool,
     *     discovery: ?bool,
     *     backfill_queued: int
     * }
     */
    public function execute(): array
    {
        $index = $this->prepareIndex();
        $this->applyFlags();

        return [
            'scope' => $this->company !== null ? 'company' : 'app',
            'engine' => ProductDiscoveryResolver::engineFor($this->app),
            'index' => $index,
            'enrichment' => $this->enrichment,
            'discovery' => $this->discovery,
            'backfill_queued' => $this->startBackfill(),
        ];
    }

    /**
     * Turning a flag off must not edit an index storefront search is reading.
     *
     * @return array{status: string, index: ?string, changes: list<string>, message: ?string}
     */
    private function prepareIndex(): array
    {
        $flags = array_filter([$this->enrichment, $this->discovery], static fn (?bool $flag): bool => $flag !== null);

        if ($flags !== [] && ! in_array(true, $flags, true)) {
            return $this->indexReport(self::INDEX_SKIPPED, message: 'Turning off only; the index was not touched.');
        }

        $adapter = $this->adapters()->resolve($this->app);

        if ($adapter === null) {
            return $this->indexReport(self::INDEX_SKIPPED, message: 'Nothing to prepare on the SQL fallback.');
        }

        try {
            $result = $adapter->ensureFields(dryRun: false, fromDefault: false);
        } catch (Throwable $e) {
            report($e);

            return $this->indexReport(self::INDEX_FAILED, message: $e->getMessage());
        }

        $status = match (true) {
            $result['refusal'] !== null => self::INDEX_REFUSED,
            $result['changes'] === [] => self::INDEX_UNCHANGED,
            default => self::INDEX_PREPARED,
        };

        return $this->indexReport(
            status: $status,
            index: $result['index'],
            changes: $result['changes'],
            message: $result['refusal'],
        );
    }

    /**
     * @param list<string> $changes
     *
     * @return array{status: string, index: ?string, changes: list<string>, message: ?string}
     */
    private function indexReport(
        string $status,
        ?string $index = null,
        array $changes = [],
        ?string $message = null
    ): array {
        return [
            'status' => $status,
            'index' => $index,
            'changes' => $changes,
            'message' => $message,
        ];
    }

    private function applyFlags(): void
    {
        $target = $this->company ?? $this->app;

        if ($this->enrichment !== null) {
            $target->set(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value, $this->enrichment);
        }

        if ($this->discovery !== null) {
            $target->set(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value, $this->discovery);
        }
    }

    /**
     * App scope never backfills: that would spend on every company in one call, including ones that
     * opted out. It is a deliberate per-company step.
     */
    private function startBackfill(): int
    {
        if ($this->enrichment !== true || $this->company === null) {
            return 0;
        }

        return new BackfillProductEnrichmentAction(app: $this->app, company: $this->company)->execute();
    }

    private function adapters(): DiscoveryIndexAdapterResolverService
    {
        return $this->adapters ??= new DiscoveryIndexAdapterResolverService();
    }
}
