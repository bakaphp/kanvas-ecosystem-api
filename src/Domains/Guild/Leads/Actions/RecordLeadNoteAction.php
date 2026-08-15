<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Actions\RecordEntityNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Override;

class RecordLeadNoteAction extends RecordEntityNoteAction
{
    public function __construct(
        protected readonly Lead $lead,
    ) {
    }

    #[Override]
    protected function entity(): BaseModel
    {
        return $this->lead;
    }

    #[Override]
    protected function resolveNotesChannel(Users $user): ?Channel
    {
        /** @var Channel|null $channel */
        $channel = $this->lead->systemNotes
            ?? $this->lead->notes
            ?? new LeadChannelService()->findOrCreateForLead(
                $this->lead,
                $this->lead->app,
                $this->lead->company,
                $user
            );

        return $channel;
    }
}
