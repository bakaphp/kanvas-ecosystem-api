<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\EventVersions;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Str;
use Kanvas\Event\Events\DataTransferObject\EventVersion as EventVersionDto;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Models\Participant;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Spatie\LaravelData\DataCollection;

class EventVersionMutation
{
    /**
     * Create a new event version (reservation instance).
     */
    public function create(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): EventVersion
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(\Kanvas\Apps\Models\Apps::class);

        $event = Event::fromApp()
            ->fromCompany()
            ->findOrFail($args['input']['event_id']);

        // Generate version number
        $nextVersion = EventVersion::where('event_id', $event->getId())
            ->max('version_number') + 1;

        // Generate slug if not provided
        $slug = $args['input']['slug'] ?? Str::slug($args['input']['name']);
        
        // Ensure slug uniqueness
        $originalSlug = $slug;
        $counter = 1;
        while (EventVersion::fromApp()->fromCompany()
            ->where('slug', $slug)
            ->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }

        $eventVersion = EventVersion::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'event_id' => $event->getId(),
            'currency_id' => $args['input']['currency_id'],
            'name' => $args['input']['name'],
            'slug' => $slug,
            'version_number' => $nextVersion,
            'version' => (string) $nextVersion,
            'description' => $args['input']['description'] ?? null,
            'classification' => $args['input']['classification'] ?? null,
            'places_comments' => $args['input']['places_comments'] ?? null,
            'participants_satisfaction' => $args['input']['participants_satisfaction'] ?? null,
            'price_per_ticket' => $args['input']['price_per_ticket'],
            'agenda' => $args['input']['agenda'] ?? null,
            'metadata' => $args['input']['metadata'] ?? null,
            'is_deleted' => false,
        ]);

        // Add dates if provided
        if (isset($args['input']['dates']) && !empty($args['input']['dates'])) {
            $dates = DataCollection::from($args['input']['dates'], EventVersionDto::class);
            $eventVersion->addDates($dates);
        }

        return $eventVersion;
    }

    /**
     * Update an existing event version.
     */
    public function update(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): EventVersion
    {
        $eventVersion = EventVersion::fromApp()
            ->fromCompany()
            ->findOrFail($args['id']);

        $updateData = array_filter($args['input'], function ($value) {
            return $value !== null;
        });

        if (isset($updateData['dates'])) {
            // Handle dates update - first remove existing ones
            $eventVersion->dates()->delete();
            
            // Add new dates
            $dates = DataCollection::from($updateData['dates'], EventVersionDto::class);
            $eventVersion->addDates($dates);
            
            unset($updateData['dates']); // Remove from update data as it's handled separately
        }

        $eventVersion->update($updateData);
        $eventVersion->refresh();

        return $eventVersion;
    }

    /**
     * Delete an event version (soft delete).
     */
    public function delete(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): bool
    {
        $eventVersion = EventVersion::fromApp()
            ->fromCompany()
            ->findOrFail($args['id']);

        $eventVersion->is_deleted = true;
        $eventVersion->save();

        return true;
    }

    /**
     * Add a participant to an event version.
     */
    public function addParticipant(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): EventVersionParticipant
    {
        $eventVersion = EventVersion::fromApp()
            ->fromCompany()
            ->findOrFail($args['event_version_id']);

        $participant = Participant::fromApp()
            ->fromCompany()
            ->findOrFail($args['participant_id']);

        return $eventVersion->addParticipant($participant);
    }

    /**
     * Remove a participant from an event version.
     */
    public function removeParticipant(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): bool
    {
        $eventVersion = EventVersion::fromApp()
            ->fromCompany()
            ->findOrFail($args['event_version_id']);

        $participant = Participant::fromApp()
            ->fromCompany()
            ->findOrFail($args['participant_id']);

        return $eventVersion->removeParticipant($participant);
    }
}