<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;

class LeadMessageMutation
{
    public function addToDefaultChannel(mixed $root, array $request): Message
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $input = $request['input'];

        $lead = $user->isAppOwner()
            ? Lead::getById((int) $input['lead_id'], $app)
            : Lead::getByIdFromBranch((int) $input['lead_id'], $branch, $app);

        // No channel_id → the lead's default channel (its slug === lead uuid, see LeadObserver).
        $channelSlug = isset($input['channel_id'])
            ? $this->resolveLeadChannel($lead, (int) $input['channel_id'], $app)->slug
            : (string) $lead->uuid;

        $verb = $input['message_verb'] ?? 'comment';

        try {
            $messageType = MessagesTypesRepository::getByVerb($verb, $app);
        } catch (ModelNotFoundException) {
            $messageType = new CreateMessageTypeAction(
                MessageTypeInput::from([
                    'apps_id' => $app->getId(),
                    'name' => $verb,
                    'verb' => $verb,
                ]),
            )->execute();
        }

        return new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $lead->company,
                user: $user,
                type: $messageType,
                message: $input['message'],
                channel_slug: (string) $channelSlug,
            ),
        )->execute();
    }

    private function resolveLeadChannel(Lead $lead, int $channelId, Apps $app): Channel
    {
        /** @var Channel $channel */
        $channel = Channel::getByIdFromCompanyApp($channelId, $lead->company, $app);

        if ((int) $channel->entity_id !== $lead->getId()) {
            throw new ValidationException('The channel does not belong to this lead');
        }

        return $channel;
    }
}
