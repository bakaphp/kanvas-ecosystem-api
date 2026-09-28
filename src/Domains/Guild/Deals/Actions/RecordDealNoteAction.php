<?php

declare(strict_types=1);

namespace Kanvas\Guild\Deals\Actions;

use Kanvas\Guild\Actions\RecordEntityNoteAction;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Override;

class RecordDealNoteAction extends RecordEntityNoteAction
{
    public function __construct(
        protected readonly Deal $deal,
    ) {
    }

    #[Override]
    protected function entity(): BaseModel
    {
        return $this->deal;
    }

    #[Override]
    protected function resolveNotesChannel(Users $user): ?Channel
    {
        /** @var Channel|null $channel */
        $channel = $this->deal->defaultChannel
            ?? $this->deal->notes
            ?? $this->createNotesChannel($user);

        return $channel;
    }

    private function createNotesChannel(Users $user): Channel
    {
        $dto = new ChannelDto(
            apps: $this->deal->app,
            companies: $this->deal->company,
            users: $user,
            entity_id: $this->deal->getId(),
            entity_namespace: Deal::class,
            name: ChannelNameEnum::NOTES->value,
            description: 'Deal notes channel.',
            slug: 'deal-notes-' . $this->deal->getId(),
        );

        return new CreateChannelAction($dto)->execute();
    }
}
