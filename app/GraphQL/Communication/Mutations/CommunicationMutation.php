<?php

declare(strict_types=1);

namespace App\GraphQL\Communication\Mutations;

use App\GraphQL\Communication\Presenters\CommunicationPresenter;
use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Connections\Actions\SetConnectionEnabledAction;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Actions\RetryDeliveryAction;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Guild\Customers\Models\People;

class CommunicationMutation
{
    use ResolvesActingContext;

    public function send(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        $people = People::getByIdFromCompanyApp((int) $input['people_id'], $ctx->company, $ctx->app);

        $content = Content::text((string) $input['content']['text']);
        $content = isset($input['content']['subject']) ? $content->withSubject((string) $input['content']['subject']) : $content;
        $content = $content->withAttachments(
            $input['content']['attachments'] ?? [],
            required: (bool) ($input['content']['attachments_required'] ?? false)
        );

        $channels = $input['channels'] === ['all'] ? 'all' : $input['channels'];

        return CommunicationPresenter::operation(
            Communication::for($ctx->app, $ctx->company)->send(
                $people,
                $content,
                channels: $channels,
                from: Sender::user($ctx->user),
                idempotencyKey: $input['idempotency_key'] ?? null,
                tag: $input['tag'] ?? null
            )
        );
    }

    /**
     * Only a failed delivery is sent again; anything else comes back unchanged, so the caller sees
     * why nothing happened.
     */
    public function retryDelivery(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();

        $delivery = Delivery::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->findOrFail((int) $request['id']);

        new RetryDeliveryAction($delivery)->execute();

        return CommunicationPresenter::delivery($delivery->refresh());
    }

    public function connectionEnabled(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();

        $connection = Connection::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->live()
            ->findOrFail((int) $request['id']);

        return CommunicationPresenter::connection(
            new SetConnectionEnabledAction($connection, (bool) $request['enabled'])->execute()
        );
    }
}
