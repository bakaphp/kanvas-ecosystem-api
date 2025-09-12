<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\TimeSlots;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\SystemModules\Models\SystemModules;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class TimeSlotsQuery
{
    /**
     * Get time slots for a specific resource.
     */
    public function getForResource(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $entityClass = SystemModules::getSystemModuleNameSpaceBySlug($args['resources_type']);

        $query = TimeSlots::fromApp()
            ->fromCompany()
            ->where('resources_id', $args['resources_id'])
            ->where('resources_type', $entityClass);

        // Filter by date range if provided
        if (isset($args['date_from'])) {
            $query->where('start_at', '>=', Carbon::parse($args['date_from'])->startOfDay());
        }

        if (isset($args['date_to'])) {
            $query->where('end_at', '<=', Carbon::parse($args['date_to'])->endOfDay());
        }

        // Filter by status if provided
        if (isset($args['status'])) {
            $query->where('status', $args['status']);
        }

        // Apply ordering
        if (isset($args['orderBy'])) {
            foreach ($args['orderBy'] as $order) {
                $query->orderBy($order['column'], $order['order'] ?? 'asc');
            }
        } else {
            $query->orderBy('start_at', 'asc');
        }

        return $query->get()->toArray();
    }

    /**
     * Get available time slots for booking.
     */
    public function getAvailable(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $capacityNeeded = $args['capacity_needed'] ?? 1;
        $entityClass = SystemModules::getSystemModuleNameSpaceBySlug($args['resources_type']);
        
        $query = TimeSlots::fromApp()
            ->fromCompany()
            ->where('resources_id', $args['resources_id'])
            ->where('resources_type', $entityClass)
            ->where('start_at', '>=', Carbon::parse($args['date_from'])->startOfDay())
            ->where('end_at', '<=', Carbon::parse($args['date_to'])->endOfDay())
            ->where('capacity', '>=', $capacityNeeded)
            ->whereIn('status', ['available', 'open', null]); // Only available slots

        // Apply ordering
        if (isset($args['orderBy'])) {
            foreach ($args['orderBy'] as $order) {
                $query->orderBy($order['column'], $order['order'] ?? 'asc');
            }
        } else {
            $query->orderBy('start_at', 'asc');
        }

        return $query->get()->toArray();
    }
}