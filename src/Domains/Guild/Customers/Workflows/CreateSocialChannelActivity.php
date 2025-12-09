<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Workflow\KanvasActivity;

class CreateSocialChannelActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Contact $contact, Apps $app, array $params): array
    {
        $contactTypesAllowed = [
            ContactTypeEnum::CELLPHONE->value,
            ContactTypeEnum::PHONE->value,
            ContactTypeEnum::EMAIL->value,
        ];
        if (! in_array($contact->contacts_types_id, $contactTypesAllowed, true)) {
            return [
                'error' => 'Contact type not allowed for social channel creation',
            ];
        }
        $lead = $contact->people->leads->first();

        $communicationChannel = match ($contact->contacts_types_id) {
            ContactTypeEnum::CELLPHONE->value => 'sms',
            ContactTypeEnum::PHONE->value => 'sms',
            ContactTypeEnum::EMAIL->value => 'email',
            default => 'unknown'
        };
        $channel = ChannelDto::from([
            'apps' => $app,
            'companies' => $lead->company,
            'users' => $lead->user,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => ucwords($communicationChannel) . ' ' . $lead->getId(),
            'slug' => SessionChannelService::createChannelSlug(
                $communicationChannel,
                $contact->value
            ),
        ]);
        $channel = (new CreateChannelAction($channel))->execute();
        if ($communicationChannel == 'sms') {
            $channel = ChannelDto::from([
                'apps' => $app,
                'companies' => $lead->company,
                'users' => $lead->user,
                'entity_id' => $lead->getId(),
                'entity_namespace' => Lead::class,
                'name' => ucwords($communicationChannel) . ' ' . $lead->getId(),
                'slug' => SessionChannelService::createChannelSlug(
                    'whatsapp',
                    $contact->value
                ),
            ]);
            $channel = (new CreateChannelAction($channel))->execute();
        }

        return [
            'success' => true,
            'channel_id' => $channel->getId(),
        ];
        // Activity code goes here
    }
}
