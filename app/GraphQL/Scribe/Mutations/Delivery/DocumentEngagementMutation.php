<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Delivery;

use App\GraphQL\Concerns\ResolvesActingContext;
use InvalidArgumentException;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Delivery\Actions\RecordDocumentResponseAction;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;

class DocumentEngagementMutation
{
    use ResolvesActingContext;

    /**
     * fromCompany scopes a logged-in user to their company; under the page's app key it allows any
     * company of the app, which is what lets the link work without a login.
     */
    public function respond(mixed $rootValue, array $request): Engagement
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        /** @var Engagement $engagement */
        $engagement = Engagement::query()
            ->where('uuid', (string) $request['uuid'])
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->notDeleted()
            ->firstOrFail();

        try {
            return new RecordDocumentResponseAction(
                engagement: $engagement,
                status: ActionStatusEnum::from((string) $input['status']),
                signerName: $input['signer_name'] ?? null,
                signature: $input['signature'] ?? null,
                reason: $input['reason'] ?? null,
                ipAddress: request()->ip(),
                userAgent: request()->userAgent(),
            )->execute();
        } catch (DocumentDeliveryException|InvalidQuoteTransitionException|InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage());
        }
    }
}
