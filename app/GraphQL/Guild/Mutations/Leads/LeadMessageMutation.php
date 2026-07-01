<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
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
                channel_slug: (string) $lead->uuid,
            ),
        )->execute();
    }
}
