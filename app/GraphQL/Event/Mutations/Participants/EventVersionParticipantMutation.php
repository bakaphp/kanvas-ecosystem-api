<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Participants;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Models\ParticipantType;

class EventVersionParticipantMutation
{
    public function update(mixed $root, array $req): EventVersionParticipant
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var EventVersionParticipant $evp */
        $evp = EventVersionParticipant::findOrFail((int) $req['id']);

        // Ensure the EventVersion belongs to the user's company
        EventVersion::getByIdFromCompanyApp((int) $evp->event_version_id, $company, $app);

        if (array_key_exists('participant_type_id', $input)) {
            $participantType = ParticipantType::getByIdFromCompanyApp(
                (int) $input['participant_type_id'],
                $company,
                $app,
            );
            $evp->participant_type_id = $participantType->getId();
        }

        foreach (['ticket_price', 'discount', 'invoice_date', 'payment_status'] as $key) {
            if (array_key_exists($key, $input)) {
                $evp->{$key} = $input[$key];
            }
        }

        if (array_key_exists('metadata', $input) && is_array($input['metadata'])) {
            $existing = is_array($evp->metadata) ? $evp->metadata : [];
            $evp->metadata = array_merge($existing, $input['metadata']);
        }

        $evp->saveOrFail();
        $evp->refresh();

        return $evp;
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var EventVersionParticipant $evp */
        $evp = EventVersionParticipant::findOrFail((int) $req['id']);

        EventVersion::getByIdFromCompanyApp((int) $evp->event_version_id, $company, $app);

        return $evp->softDelete();
    }
}
