<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Kanvas\Guild\Actions\CreateEntityNotesChannelAction;
use Kanvas\Guild\Actions\RecordEntityNoteAction;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Override;

class RecordOrganizationNoteAction extends RecordEntityNoteAction
{
    public function __construct(
        protected readonly Organization $organization,
    ) {
    }

    #[Override]
    protected function entity(): BaseModel
    {
        return $this->organization;
    }

    #[Override]
    protected function resolveNotesChannel(Users $user): ?Channel
    {
        return $this->organization->notes
            ?? new CreateEntityNotesChannelAction($this->organization)->execute($user);
    }
}
