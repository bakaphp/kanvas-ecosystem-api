<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Customers\Actions\ProcessInboundConsentAction;
use Kanvas\Guild\Customers\DataTransferObject\ConsentOutcome;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SendOptOutConfirmationAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Consent detection for inbound mail, shared by both Mailgun receivers — the company lead inbox and
 * an agent's own mailbox.
 *
 * Email needs a pass the other channels do not: a one-word "Unsubscribe" lands in the SUBJECT at
 * least as often as in the body, usually with nothing under it. Everything else is the generic
 * ProcessInboundConsentAction.
 */
final class MailgunConsentService
{
    public static function process(Model $entity, MailgunPayloadService $payload): ?ConsentOutcome
    {
        [$people, $lead] = self::split($entity);

        if ($people === null) {
            return null;
        }

        $outcome = self::detect($people, $lead, $payload, $payload->text());

        if ($outcome->signal === null && ! $outcome->narrowedRequest) {
            $outcome = self::detect($people, $lead, $payload, $payload->subject());
        }

        if ($outcome->applied && $outcome->isStop() && $lead !== null) {
            new SendOptOutConfirmationAction($lead, LeadCommunicationChannelEnum::EMAIL->value)->execute();
        }

        return $outcome;
    }

    private static function detect(
        People $people,
        ?Lead $lead,
        MailgunPayloadService $payload,
        string $text,
    ): ConsentOutcome {
        return new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::EMAIL->value,
            body: $text,
            lead: $lead,
            contactValue: $payload->sender(),
        )->execute();
    }

    /**
     * Both receivers hand back either a Lead or the People row directly, depending on whether the
     * sender is a prospect in a pipeline or just someone the company knows.
     *
     * @return array{0: People|null, 1: Lead|null}
     */
    private static function split(Model $entity): array
    {
        return match (true) {
            $entity instanceof Lead => [$entity->people, $entity],
            $entity instanceof People => [$entity, null],
            default => [null, null],
        };
    }
}
