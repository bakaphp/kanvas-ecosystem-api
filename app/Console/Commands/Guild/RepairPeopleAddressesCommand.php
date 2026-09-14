<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\People;

/**
 * Repairs rows written while `is_default` defaulted to 1 on both the column and the DTO, which let
 * a previous-home row from a credit app shadow the address the customer actually lives at.
 */
class RepairPeopleAddressesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-guild:repair-people-addresses
                            {--apps_id= : Limit to people belonging to this app}
                            {--peoples_id=* : Repair only these people (repeatable)}
                            {--chunk=500 : People per chunk}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Collapse duplicate default addresses, demote previous homes, and unmangle multi-line streets';

    public function handle(): int
    {
        /** @var Apps|null $app */
        $app = $this->option('apps_id') !== null
            ? Apps::getById((int) $this->option('apps_id'))
            : null;

        if ($app !== null) {
            $this->overwriteAppService($app);
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');

        $previousHomeTypeIds = AddressType::query()
            ->where('name', AddressTypeEnum::PREVIOUS_HOME->value)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $stats = [
            'people' => 0,
            'streets_split' => 0,
            'previous_homes_demoted' => 0,
            'duplicate_defaults_collapsed' => 0,
            'missing_defaults_promoted' => 0,
        ];

        $only = array_map('intval', (array) $this->option('peoples_id'));
        $lastPeopleId = 0;

        while (true) {
            $peopleIds = Address::query()
                ->where('peoples_id', '>', $lastPeopleId)
                ->when($only !== [], fn ($query) => $query->whereIn('peoples_id', $only))
                ->when($app !== null, fn ($query) => $query->whereIn(
                    'peoples_id',
                    People::query()->fromApp($app)->select('id')
                ))
                ->orderBy('peoples_id')
                ->distinct()
                ->limit($chunk)
                ->pluck('peoples_id')
                ->map(fn ($id): int => (int) $id);

            if ($peopleIds->isEmpty()) {
                break;
            }

            $lastPeopleId = (int) $peopleIds->last();

            Address::query()
                ->whereIn('peoples_id', $peopleIds)
                ->orderBy('id')
                ->get()
                ->groupBy('peoples_id')
                ->each(function (Collection $addresses) use ($previousHomeTypeIds, $dryRun, &$stats): void {
                    $stats['people']++;
                    $this->repairPerson($addresses, $previousHomeTypeIds, $dryRun, $stats);
                });

            $this->line("… {$stats['people']} people, through peoples_id {$lastPeopleId}");
        }

        $this->table(['metric', 'count'], collect($stats)->map(
            fn ($count, $metric): array => [$metric, $count]
        )->values()->all());

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * @param Collection<int, Address> $addresses
     * @param list<int>                $previousHomeTypeIds
     * @param array<string, int>       $stats
     */
    private function repairPerson(
        Collection $addresses,
        array $previousHomeTypeIds,
        bool $dryRun,
        array &$stats
    ): void {
        foreach ($addresses as $address) {
            $lines = AddressData::splitStreetLines((string) $address->address);

            if (count($lines) < 2) {
                continue;
            }

            $stats['streets_split']++;

            if (! $dryRun) {
                $address->address = $lines[0];
                if (empty($address->address_2)) {
                    $address->address_2 = implode(' ', array_slice($lines, 1));
                }
                $address->saveOrFail();
            }
        }

        $isPreviousHome = fn (Address $address): bool => in_array(
            (int) $address->address_type_id,
            $previousHomeTypeIds,
            true
        );

        // A previous home is by definition not where the customer lives now — but only when there
        // is something else to fall back to, otherwise we would leave the person with no address.
        $pool = $addresses->reject($isPreviousHome);
        $pool = $pool->isNotEmpty() ? $pool : $addresses;

        // An existing default that is already sane stays put; only pick a new one when every
        // default is a previous home, or when there is none at all.
        $keep = $pool->filter(fn (Address $address): bool => (bool) $address->is_default)
            ->sortByDesc('id')
            ->first()
            ?? $pool->sortByDesc('id')->first();

        if ($keep === null) {
            return;
        }

        foreach ($addresses as $address) {
            $shouldBeDefault = $address->is($keep);

            if ((bool) $address->is_default === $shouldBeDefault) {
                continue;
            }

            if ($shouldBeDefault) {
                $stats['missing_defaults_promoted']++;
            } elseif ($isPreviousHome($address)) {
                $stats['previous_homes_demoted']++;
            } else {
                $stats['duplicate_defaults_collapsed']++;
            }

            if (! $dryRun) {
                $address->forceFill(['is_default' => $shouldBeDefault ? 1 : 0])->saveOrFail();
            }
        }
    }
}
