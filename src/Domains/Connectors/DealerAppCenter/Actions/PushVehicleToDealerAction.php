<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerAppCenter\Actions;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Upserts a mapped vehicle (see MapProductToVehicleAction) plus its equipment/photos/vehicle-type
 * child rows directly into the dealer-api database. Delete-then-insert on the child tables keeps
 * repeated runs idempotent instead of accumulating duplicates.
 */
class PushVehicleToDealerAction
{
    public function __construct(
        private array $mapped,
        private Connection $dealerConnection,
    ) {
    }

    public function execute(): int
    {
        $vehicle = $this->mapped['vehicle'];

        $this->dealerConnection->table('vehicles')->updateOrInsert(['vin' => $vehicle['vin']], $vehicle);
        $vehicleId = (int) $this->dealerConnection->table('vehicles')->where('vin', $vehicle['vin'])->value('id');

        $this->dealerConnection->table('vehicle_equipment')->where('vehicles_id', $vehicleId)->delete();
        if ($this->mapped['equipment'] !== []) {
            $this->dealerConnection->table('vehicle_equipment')->insert(
                array_map(fn (array $row) => $row + ['vehicles_id' => $vehicleId], $this->mapped['equipment'])
            );
        }

        $this->dealerConnection->table('vehicle_media')->where('vehicles_id', $vehicleId)->delete();
        if ($this->mapped['photos'] !== []) {
            $this->dealerConnection->table('vehicle_media')->insert(
                array_map(fn (array $row) => $row + ['vehicles_id' => $vehicleId], $this->mapped['photos'])
            );
        }

        // dealer-api's Vehicles::getSellingPrice() reads `vehicles_prices` (prices_id=1 'selling_price',
        // prices_types_id=1 'price'), NOT the flat `sale_price` column — keep both in sync.
        $this->dealerConnection->table('vehicles_prices')
            ->where('vehicles_id', $vehicleId)
            ->where('prices_id', 1)
            ->where('prices_types_id', 1)
            ->delete();
        $this->dealerConnection->table('vehicles_prices')->insert([
            'prices_id' => 1,
            'prices_types_id' => 1,
            'vehicles_id' => $vehicleId,
            'value' => $vehicle['sale_price'],
            'is_active' => $vehicle['sale_price'] > 0 ? 1 : 0,
        ]);

        $this->dealerConnection->table('vehicles_types')->where('vehicles_id', $vehicleId)->delete();
        if ($this->mapped['vehicle_type_ids'] !== []) {
            $this->dealerConnection->table('vehicles_types')->insert(
                array_map(fn (int $typeId) => [
                    'vehicle_types_id' => $typeId,
                    'vehicles_id' => $vehicleId,
                    'users_id' => 1, // placeholder — no acting-user mapping between kanvas and dealer yet
                    'created_at' => now(),
                ], $this->mapped['vehicle_type_ids'])
            );
        }

        return $vehicleId;
    }

    /**
     * dealer-api's `mydealer` database lives on the SAME MySQL server as Kanvas's own connection
     * (confirmed against the shared prod RDS cluster) — reuse the `mysql` connection's host/port/
     * credentials and only swap the database name, instead of duplicating them under DB_DEALER_*.
     */
    public static function resolveDealerConnection(): Connection
    {
        config([
            'database.connections.dealer' => array_merge(
                config('database.connections.mysql'),
                ['database' => 'mydealer'],
            ),
        ]);

        return DB::connection('dealer');
    }
}
