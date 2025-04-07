<?php

declare(strict_types=1);

namespace Kanvas\Event\Support;

use Baka\Contracts\AppInterface;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Event\Events\Models\EventVersionFacilitator;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Events\Models\EventVersionParticipantDate;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;

class CreateSystemModule
{
    public function __construct(
        protected AppInterface $app
    ) {
    }

    public function run(): void
    {
        $createSystemModule = new CreateInCurrentAppAction($this->app);

        $createSystemModule->execute(Event::class);
        $createSystemModule->execute(Facilitator::class);
        $createSystemModule->execute(Participant::class);
        $createSystemModule->execute(ParticipantPass::class);
        $createSystemModule->execute(ParticipantPassMotive::class);
        $createSystemModule->execute(ParticipantType::class);
        $createSystemModule->execute(EventVersion::class);
        $createSystemModule->execute(EventCategory::class);
        $createSystemModule->execute(EventClass::class);
        $createSystemModule->execute(EventStatus::class);
        $createSystemModule->execute(EventType::class);
        $createSystemModule->execute(EventVersionDate::class);
        $createSystemModule->execute(EventVersionFacilitator::class);
        $createSystemModule->execute(EventVersionParticipant::class);
        $createSystemModule->execute(EventVersionParticipantDate::class);
    }
}
