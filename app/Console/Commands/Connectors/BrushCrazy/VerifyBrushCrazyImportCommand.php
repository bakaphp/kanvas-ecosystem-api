<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\BrushCrazy;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\PullCustomersFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\PullPaintingsFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\PullStaffFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\ResolveStudioContextsAction;
use Kanvas\Connectors\BrushCrazy\Actions\RetireMovedBrushCrazyEntityAction;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Read-only reconciliation of an import: for each studio, the source ids the pull would sync
 * against the BrushCrazy ids Kanvas has mapped. It only uses ResolveStudioContextsAction's read-only
 * company lookup, never execute(), which creates companies, so running it cannot change either side.
 *
 * Only "missing" fails the run. "Not in source" is expected for rows that left the import scope
 * after being synced (a voided sale, a departed instructor) and are soft-deleted rather than unmapped.
 */
class VerifyBrushCrazyImportCommand extends Command
{
    use KanvasJobsTrait;

    private const int SAMPLE_SIZE = 10;

    protected $signature = 'kanvas:brushcrazy-verify
                            {app_id : The application ID}
                            {--studio=* : Only these BrushCrazy studio ids (repeatable)}';

    protected $description = 'Compare BrushCrazy source rows with what the import mapped into Kanvas, per studio';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $client = new Client($app);
        $idMaps = new IdMapRepository();
        $missingTotal = 0;
        $movedTotal = 0;
        $unmappedStudios = 0;

        foreach ($this->studioIds($client) as $bcStudioId) {
            $company = ResolveStudioContextsAction::companyForStudio($app, $bcStudioId);

            $this->line('');

            if ($company === null) {
                $this->error("Studio {$bcStudioId}: no Kanvas company; run the import with --entity=studios first.");
                $unmappedStudios++;

                continue;
            }

            $this->info("Studio {$bcStudioId} → company {$company->getId()} ({$company->name})");

            $rows = [];
            foreach ($this->sourceIds($client, $bcStudioId) as $entity => [$modelClass, $field, $sourceIds]) {
                $mapped = array_keys($idMaps->load((int) $company->getId(), $modelClass, $field));
                $missing = array_values(array_diff($sourceIds, $mapped));
                $notInSource = array_diff($mapped, $sourceIds);
                $missingTotal += count($missing);
                $moved = $this->movedNotRetired(
                    $client,
                    $entity,
                    $bcStudioId,
                    (int) $company->getId(),
                    array_values($notInSource)
                );
                $movedTotal += $moved ?? 0;

                $rows[] = [
                    $entity,
                    count($sourceIds),
                    count($mapped),
                    count($missing),
                    count($notInSource),
                    $moved ?? '-',
                    implode(', ', array_slice($missing, 0, self::SAMPLE_SIZE)),
                ];
            }

            $this->table(
                [
                    'Entity',
                    'Source',
                    'Mapped',
                    'Missing',
                    'Not in source',
                    'Moved, live here',
                    'Missing sample',
                ],
                $rows
            );
        }

        if ($movedTotal > 0) {
            $this->warn("{$movedTotal} mirrored rows belong to another studio now but are still live in the old company; a full pull (no --since) retires them.");
        }

        if ($missingTotal > 0 || $unmappedStudios > 0) {
            $this->error("{$unmappedStudios} studios without a company, {$missingTotal} source rows without a Kanvas counterpart.");

            return self::FAILURE;
        }

        $this->info('Every source row is mapped.');

        return self::SUCCESS;
    }

    /**
     * Rows BrushCrazy moved to another studio whose copy here was never retired (the vacated-studio
     * pointer was lost or predates it). Only classes and sales: staff stay live by design and are
     * only marked inactive.
     *
     * @param  array<int, int|string>  $notInSource
     */
    private function movedNotRetired(
        Client $client,
        string $entity,
        int $bcStudioId,
        int $companyId,
        array $notInSource
    ): ?int {
        return match ($entity) {
            'calendarables' => count(RetireMovedBrushCrazyEntityAction::pendingCalendarables(
                $client,
                $bcStudioId,
                $companyId,
                array_map('strval', $notInSource)
            )),
            'sales' => count(RetireMovedBrushCrazyEntityAction::pendingSales(
                $client,
                $bcStudioId,
                $companyId,
                array_map('intval', $notInSource)
            )),
            default => null,
        };
    }

    /**
     * @return array<int, int>
     */
    private function studioIds(Client $client): array
    {
        $all = $client->table('studios')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $only = array_map('intval', (array) $this->option('studio'));

        if ($only === []) {
            return $all;
        }

        $unknown = array_diff($only, $all);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown BrushCrazy studio ids: ' . implode(', ', $unknown));
        }

        return $only;
    }

    /**
     * Each scope is the one its pull action syncs, so a difference means the import fell short and
     * not that the two sides disagree on what belongs to the studio. Registrations are left out:
     * their participants are merged per buyer and keyed under company 0, so there is no per-studio
     * id map to compare against.
     *
     * @return array<string, array{0: class-string, 1: CustomFieldEnum, 2: array<int, int|string>}>
     */
    private function sourceIds(Client $client, int $bcStudioId): array
    {
        $calendarables = [];
        foreach (CalendarableTypeEnum::cases() as $morph) {
            foreach ($client->table($morph->table())->where('studio_id', $bcStudioId)->pluck('id') as $id) {
                $calendarables[] = $morph->key($id);
            }
        }

        // SaleMapper::isImportable(): open tickets, deleted sales and refund mirrors never become orders.
        $sales = $client->table('sales')
            ->where('studio_id', $bcStudioId)
            ->whereNull('original_sale')
            ->whereNull('deleted_at')
            ->whereNotNull('completed_at')
            ->pluck('id');

        return [
            'paintings' => [
                Theme::class,
                CustomFieldEnum::BRUSHCRAZY_PAINTING_ID,
                PullPaintingsFromBrushCrazyAction::paintingsOfStudio($client, $bcStudioId)
                    ->pluck('paintings.id')
                    ->all(),
            ],
            'customers' => [
                People::class,
                CustomFieldEnum::BRUSHCRAZY_USER_ID,
                PullCustomersFromBrushCrazyAction::customersOfStudio($client, $bcStudioId)
                    ->pluck('users.id')
                    ->all(),
            ],
            'staff' => [
                Facilitator::class,
                CustomFieldEnum::BRUSHCRAZY_USER_ID,
                PullStaffFromBrushCrazyAction::staffOfStudio($client, $bcStudioId)->pluck('users.id')->all(),
            ],
            'calendarables' => [
                EventVersion::class,
                CustomFieldEnum::BRUSHCRAZY_CALENDARABLE_KEY,
                $calendarables,
            ],
            'sales' => [
                Order::class,
                CustomFieldEnum::BRUSHCRAZY_SALE_ID,
                $sales->all(),
            ],
        ];
    }
}
