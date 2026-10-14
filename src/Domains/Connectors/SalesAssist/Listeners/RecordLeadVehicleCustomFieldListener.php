<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Listeners;

use Kanvas\Connectors\SalesAssist\Actions\RecordLeadVehicleNotesAction;
use Kanvas\Connectors\SalesAssist\Enums\LeadVehicleNoteEnum;
use Kanvas\Guild\Leads\Events\LeadCustomFieldSavedEvent;

/**
 * Runs inline with the custom-field write; the action reports its own failures so the save
 * (a CRM pull or the app's mutation) that triggered it never fails.
 */
final class RecordLeadVehicleCustomFieldListener
{
    public function handle(LeadCustomFieldSavedEvent $event): void
    {
        $kind = LeadVehicleNoteEnum::fromCustomField($event->name);
        if ($kind === null) {
            return;
        }

        new RecordLeadVehicleNotesAction($event->lead, $kind)->execute();
    }
}
