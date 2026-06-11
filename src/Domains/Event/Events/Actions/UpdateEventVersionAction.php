<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Support\Str;
use Kanvas\Event\Events\DataTransferObject\EventDate;
use Kanvas\Event\Events\Models\EventVersion as ModelsEventVersion;
use Spatie\LaravelData\DataCollection;

class UpdateEventVersionAction
{
    public function __construct(
        protected ModelsEventVersion $existingEventVersion,
        protected array $updateData
    ) {
    }

    public function execute(): ModelsEventVersion
    {
        $updateFields = [];

        if (isset($this->updateData['name'])) {
            $updateFields['name'] = $this->updateData['name'];
            $updateFields['slug'] = Str::slug('events-versions-' . $this->updateData['name'] . '-' . now()->format('Y-m-d'));
        }

        if (isset($this->updateData['description'])) {
            $updateFields['description'] = $this->updateData['description'];
        }

        if (isset($this->updateData['price_per_ticket'])) {
            $updateFields['price_per_ticket'] = $this->updateData['price_per_ticket'];
        }

        if (isset($this->updateData['agenda'])) {
            $updateFields['agenda'] = $this->updateData['agenda'];
        }

        if (isset($this->updateData['metadata'])) {
            $existingMetadata = $this->existingEventVersion->metadata ?? [];
            $updateFields['metadata'] = array_merge($existingMetadata, $this->updateData['metadata']);
        }

        if (isset($this->updateData['classification'])) {
            $updateFields['classification'] = $this->updateData['classification'];
        }

        // Update the event version
        if (! empty($updateFields)) {
            $this->existingEventVersion->update($updateFields);
        }

        // Update dates if provided
        if (isset($this->updateData['dates'])) {
            // Delete existing dates
            $this->existingEventVersion->dates()->delete();

            // addDates() requires a DataCollection<EventDate>, not a plain Collection —
            // build it the same way Event::fromMultiple does, or it throws a TypeError.
            $this->existingEventVersion->addDates(
                EventDate::collect($this->updateData['dates'], DataCollection::class),
            );
        }

        return $this->existingEventVersion->fresh();
    }
}
