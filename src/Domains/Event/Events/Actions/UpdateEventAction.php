<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Event\Events\Models\Event as ModelsEvent;
use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Event\Events\Models\EventResource;
use Kanvas\Event\Events\DataTransferObject\EventDate;
use Kanvas\Event\Events\Validators\EventTimeSlotValidator;
use Spatie\LaravelData\DataCollection;
use Kanvas\Event\Events\Models\EventVersion as ModelsEventVersion;
use Kanvas\Event\Participants\Actions\CreateParticipantAction;
use Kanvas\SystemModules\Models\SystemModules;

class UpdateEventAction
{
    public function __construct(
        protected ModelsEventVersion $eventVersion,
        protected array $updateData
    ) {
    }

    public function execute(): ModelsEventVersion
    {
        // Validate time slot availability if dates are being updated
        if (isset($this->updateData['dates'])) {
            $this->validateTimeSlotAvailability();
        }

        $eventVersion = DB::connection('event')->transaction(function () {
            $event = $this->eventVersion->event;

            // Update the main Event model if needed
            $eventUpdateData = [];
            if (isset($this->updateData['name'])) {
                $eventUpdateData['name'] = $this->updateData['name'];
            }
            if (isset($this->updateData['description'])) {
                $eventUpdateData['description'] = $this->updateData['description'];
            }
            if (isset($this->updateData['resources_id']) && isset($this->updateData['resources_type'])) {
                $eventUpdateData['resources_id'] = $this->updateData['resources_id'];
                $eventUpdateData['resources_type'] = $this->updateData['resources_type'];
            }
            if (isset($this->updateData['theme_id'])) {
                $eventUpdateData['theme_id'] = $this->updateData['theme_id'];
            }
            if (isset($this->updateData['theme_area_id'])) {
                $eventUpdateData['theme_area_id'] = $this->updateData['theme_area_id'];
            }
            if (isset($this->updateData['status_id'])) {
                $eventUpdateData['event_status_id'] = $this->updateData['status_id'];
            }

            if (!empty($eventUpdateData)) {
                if (isset($eventUpdateData['name'])) {
                    $slug = Str::slug($eventUpdateData['name']) . '-' . $event->event_type_id;
                    $eventUpdateData['slug'] = $slug;
                }

                $event->name = $eventUpdateData['name'];
                $event->description = $eventUpdateData['description'] ?? null;
                $event->saveOrFail();
            }

            // Update the EventVersion model
            $versionUpdateData = [];
            if (isset($this->updateData['name'])) {
                $versionUpdateData['name'] = $this->updateData['name'];
                $versionUpdateData['slug'] = Str::slug('events-versions-' . $this->updateData['name'] . '-' . now()->format('Y-m-d'));
            }
            if (isset($this->updateData['description'])) {
                $versionUpdateData['description'] = $this->updateData['description'];
            }
            if (isset($this->updateData['participants'])) {
                $versionUpdateData['participants'] = $this->updateData['participants'];
            }
            if (isset($this->updateData['resources'])) {
                $versionUpdateData['resources'] = $this->updateData['resources'];
            }
            if (isset($this->updateData['metadata'])) {
                $existingMetadata = $this->eventVersion->metadata ?? [];
                $versionUpdateData['metadata'] = array_merge($existingMetadata, $this->updateData['metadata']);
            }

            // Update dates if provided
            if (isset($this->updateData['dates'])) {
                // Delete existing dates
                $this->eventVersion->dates()->delete();

                // Add new dates - convert to EventDate DTOs and create DataCollection
                $eventDates = EventDate::collect($this->updateData['dates'], DataCollection::class);
                $this->eventVersion->addDates($eventDates);
            }

            // Handle participants update
            if (isset($this->updateData['participants'])) {
                // For simplicity, we'll delete existing participants and recreate them
                // In a production system, you might want to handle this more elegantly
                $this->eventVersion->participants()->delete();

                foreach ($this->updateData['participants'] as $participant) {
                    $createParticipant = new CreateParticipantAction(
                        $event->app,
                        $event->company->defaultBranch,
                        $event->user,
                        $participant,
                        $this->eventVersion,
                        $participant
                    );
                    $createParticipant->execute();
                }
            }

            // Handle additional resources update
            if (isset($this->updateData['resources'])) {
                // Delete existing event resources
                EventResource::where('event_id', $event->getId())->delete();

                // Create new ones
                $this->storeEventResources($event, $this->updateData['resources']);
            }

            return $this->eventVersion->fresh();
        });

        return $eventVersion;
    }

    protected function validateTimeSlotAvailability(): void
    {
        $event = $this->eventVersion->event;
        $dateData = $this->updateData['dates'][0]; // Assuming single date for now

        // Get resource information
        $resourcesId = $this->updateData['resources_id'] ?? $event->resources_id;
        $resourcesType = $this->updateData['resources_type'] ?? $event->resources_type;

        if (!$resourcesId || !$resourcesType) {
            return; // No resource to validate against
        }

        // Parse the new time slot
        $newDate = $dateData['date'];
        $newStartTime = $dateData['start_time'];
        $newEndTime = $dateData['end_time'];

        // Use shared validator
        EventTimeSlotValidator::validateForUpdate(
            $resourcesId,
            $resourcesType,
            $event->companies_id,
            $event->apps_id,
            $newDate,
            $newStartTime,
            $newEndTime,
            $event->id
        );
    }

    protected function storeEventResources(ModelsEvent $event, array $resources): void
    {
        foreach ($resources as $resourceData) {
            if (isset($resourceData['resources_id']) && isset($resourceData['resources_type'])) {
                $resourceClass = SystemModules::getSystemModuleNameSpaceBySlug($resourceData['resources_type']);
                EventResource::create([
                    'apps_id' => $event->apps_id,
                    'companies_id' => $event->companies_id,
                    'event_id' => $event->getId(),
                    'resources_id' => $resourceData['resources_id'],
                    'resources_type' => $resourceClass,
                    'metadata' => $resourceData['metadata'] ?? null,
                ]);
            }
        }
    }
}