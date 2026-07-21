<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyCustomerResponse;
use Kanvas\Inventory\Products\Models\Products;
use Throwable;

class SyncVehicleTagDataAction
{
    /**
     * Every save() clears the product's Lighthouse cache, so an unthrottled write would
     * invalidate it on each balance check. Bounds that to one write per window per vehicle.
     */
    private const REFRESH_WINDOW_SECONDS = 300;

    public function __construct(
        protected readonly Products $vehicle,
        protected readonly VerifyCustomerResponse $response,
    ) {
    }

    /**
     * Single definition of the tag attribute names, shared with the recharge path
     * (UpdateVehicleTagTelemetryAction) so both can't drift apart.
     */
    public static function buildAttributes(VerifyCustomerResponse $response, Carbon $fetchedAt): array
    {
        return [
            ['name' => 'tag_balance', 'value' => (string) $response->balance],
            ['name' => 'tag_balance_fetched_at', 'value' => $fetchedAt->toIso8601String()],
            ['name' => 'tag_account', 'value' => $response->account],
        ];
    }

    public function execute(): array
    {
        $now = Carbon::now();

        if (! $this->shouldWrite($now)) {
            return ['status' => 'skipped', 'reason' => 'tag data unchanged'];
        }

        $author = $this->vehicle->user;

        if ($author === null) {
            return ['status' => 'skipped', 'reason' => 'vehicle has no owner'];
        }

        $this->vehicle->addAttributes($author, self::buildAttributes($this->response, $now));
        $this->vehicle->save();

        return [
            'status' => 'success',
            'product_id' => $this->vehicle->getId(),
            'tag_balance' => $this->response->balance,
        ];
    }

    private function shouldWrite(Carbon $now): bool
    {
        if ($this->storedValue('tag_balance') !== (string) $this->response->balance) {
            return true;
        }

        if ($this->storedValue('tag_account') !== $this->response->account) {
            return true;
        }

        $fetchedAt = $this->storedValue('tag_balance_fetched_at');

        if ($fetchedAt === null) {
            return true;
        }

        try {
            $parsed = Carbon::parse($fetchedAt);
        } catch (Throwable) {
            return true;
        }

        return $parsed->addSeconds(self::REFRESH_WINDOW_SECONDS)->isBefore($now);
    }

    private function storedValue(string $name): ?string
    {
        // addAttributes() slugifies the name when it creates the attribute, so read it back the same way.
        $value = $this->vehicle->getAttributeBySlug(Str::slug($name))?->value;

        return $value === null ? null : (string) $value;
    }
}
