<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Twilio\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\Twilio\Actions\ResendFailedMessageAction;
use Kanvas\Social\Messages\Models\Message;

class TwilioMessageMutation
{
    use ResolvesActingContext;

    public function resend(mixed $root, array $request): Message
    {
        $ctx = $this->actingContext();

        /** @var Message $message */
        $message = Message::getByIdFromCompanyApp((int) $request['message_id'], $ctx->company, $ctx->app);

        return new ResendFailedMessageAction($message)->execute();
    }
}
