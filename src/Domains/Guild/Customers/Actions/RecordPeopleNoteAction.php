<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Guild\Actions\CreateEntityNotesChannelAction;
use Kanvas\Guild\Actions\RecordEntityNoteAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Override;

class RecordPeopleNoteAction extends RecordEntityNoteAction
{
    public function __construct(
        protected readonly People $people,
    ) {
    }

    #[Override]
    protected function entity(): BaseModel
    {
        return $this->people;
    }

    /**
     * Never falls back to the people-channel-{id} conversation channel: that one is replayed to the
     * LLM as agent history, and a human note dropped in it would read back as something the agent said.
     */
    #[Override]
    protected function resolveNotesChannel(Users $user): ?Channel
    {
        return $this->people->notes
            ?? new CreateEntityNotesChannelAction($this->people)->execute($user);
    }
}
