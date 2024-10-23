<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Google\Cloud\DiscoveryEngine\V1\UserEvent;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Google\Services\DiscoveryEngineUserEventService;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Models\UsersInteractions;

class SyncUserInteractionToEventAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
    ) {
    }

    public function execute(
        ?Interactions $userInteraction = null,
        array $userInteractionIds = [],
        int $interactionPerBatch = 100
    ): array {
        $query = UsersInteractions::fromApp($this->app)
            ->orderBy('id', 'DESC');

        if ($userInteraction) {
            $query->where('interactions_id', $userInteraction->id);
        }

        if (! empty($userInteractionIds)) {
            $query->whereIn('id', $userInteractionIds);
        }

        $userEventService = new DiscoveryEngineUserEventService($this->app, $this->company);
        $totalProcessed = [
            'total' => 0,
            'success' => 0,
            'error' => 0,
        ];

        $query->chunk($interactionPerBatch, function ($interactions) use ($userEventService, &$totalProcessed) {
            foreach ($interactions as $interaction) {
                $totalProcessed['total']++;

                try {
                    if ($userEventService->createUserEvent($interaction) instanceof UserEvent) {
                        $totalProcessed['success']++;
                    } else {
                        $totalProcessed['error']++;
                    }
                } catch (Exception $e) {
                    $totalProcessed['error']++;
                    Log::error($e->getMessage());
                }
            }
        });

        return $totalProcessed;
    }
}
