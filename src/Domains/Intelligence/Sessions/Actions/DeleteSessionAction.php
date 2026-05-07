<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Models\Session;

class DeleteSessionAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(): void
    {
        $sessions = Session::where('entity_namespace', Lead::class)
            ->where('entity_id', $this->lead->getId())
            ->get();

        foreach ($sessions as $session) {
            $session->delete();
        }
    }
}
