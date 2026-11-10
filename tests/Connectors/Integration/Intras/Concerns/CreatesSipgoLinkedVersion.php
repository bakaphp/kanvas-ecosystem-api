<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Support\Setup;

trait CreatesSipgoLinkedVersion
{
    /**
     * A fresh Kanvas event version carrying a SIPGO id. Always a new row: tests run in parallel against
     * the same company, so re-linking an existing version would steal it from a sibling test.
     */
    protected function createSipgoLinkedVersion(
        Apps $app,
        Companies $company,
        int $legacyId,
        string $name = 'SIPGO VERSION',
        ?int $maxCapacity = null
    ): EventVersion {
        new Setup($app, static::$cachedUser, $company)->run();

        $response = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) { id versions { data { id } } }
            }
        ', ['input' => [
            'name' => $name . ' ' . uniqid(),
            'description' => 'SIPGO test',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [['date' => now()->addMonth()->toDateString(), 'start_time' => '09:00', 'end_time' => '17:00']],
        ]])->assertSuccessful();

        /** @var EventVersion $version */
        $version = EventVersion::getById((int) $response->json('data.createEvent.versions.data.0.id'), $app);

        if ($maxCapacity !== null) {
            $version->metadata = array_merge($version->metadata ?? [], ['max_capacity' => $maxCapacity]);
            $version->saveQuietly();
        }

        $version->set(CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value, $legacyId);

        return $version;
    }
}
