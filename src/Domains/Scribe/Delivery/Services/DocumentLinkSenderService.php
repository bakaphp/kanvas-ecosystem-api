<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Services;

use Baka\Support\Str;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Actions\Communication\SendMessageToPeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\ConsentGuard;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Intelligence\Agents\Actions\Outreach\PersistOutboundMessageAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Scribe\Delivery\DataTransferObject\DeliveryTarget;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Notifications\Enums\ScribeEmailTemplateEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * Carries an engagement link to a person through SendMessageToPeopleAction: by email (the agent's
 * mailbox when it has one, else the company's email connection) with the PDFs attached, or on the
 * company's WhatsApp connection with each PDF as a document, whatever vendor carries it. Either way
 * the sent text is filed on the person's protocol channel, which is where their reply lands, so the
 * agent reads both in one thread.
 */
class DocumentLinkSenderService
{
    /**
     * Prefer email when both channels are available.
     */
    public function resolveTarget(People $people, ?LeadCommunicationChannelEnum $preferred = null): DeliveryTarget
    {
        if (ConsentGuard::isPersonDoNotContact($people)) {
            throw new DocumentDeliveryException(
                DocumentDeliveryException::DO_NOT_CONTACT,
                "{$people->getDisplayName()} asked not to be contacted."
            );
        }

        $candidates = match ($preferred) {
            LeadCommunicationChannelEnum::EMAIL => [LeadCommunicationChannelEnum::EMAIL],
            LeadCommunicationChannelEnum::WHATSAPP => [LeadCommunicationChannelEnum::WHATSAPP],
            default => [LeadCommunicationChannelEnum::EMAIL, LeadCommunicationChannelEnum::WHATSAPP],
        };

        foreach ($candidates as $channel) {
            $target = $this->targetFor($people, $channel);
            if ($target !== null) {
                return $target;
            }
        }

        if ($preferred === LeadCommunicationChannelEnum::WHATSAPP && ! $this->whatsAppConfigured($people)) {
            throw new DocumentDeliveryException(
                DocumentDeliveryException::WHATSAPP_UNAVAILABLE,
                'This company has no WhatsApp number connected.'
            );
        }

        throw new DocumentDeliveryException(
            DocumentDeliveryException::NO_DESTINATION,
            "{$people->getDisplayName()} has no "
            . ($preferred !== null ? $preferred->value : 'email or WhatsApp number')
            . ' we can send to.'
        );
    }

    /**
     * @param list<Filesystem> $files
     */
    public function send(
        DeliveryTarget $target,
        People $people,
        Users $user,
        string $body,
        string $subject,
        array $files = [],
        ?Agent $agent = null
    ): Message {
        $urls = array_values(array_filter(array_map(static fn (Filesystem $file): ?string => $file->url, $files)));

        $response = new SendMessageToPeopleAction($people)->execute(
            channel: ChannelTypeEnum::from($target->channel->value),
            to: $target->destination,
            message: $body,
            subject: $subject,
            attachmentUrls: $urls,
            fromAgent: $agent,
            emailTemplate: ScribeEmailTemplateEnum::DOCUMENT_DELIVERY->value,
        );

        return new PersistOutboundMessageAction(
            entity: $people,
            user: $user,
            channelType: $target->channel->value,
            recipient: $target->destination,
            content: $body,
            subject: $target->channel === LeadCommunicationChannelEnum::EMAIL ? $subject : null,
            agent: $agent,
            fromAi: $agent !== null,
            tag: 'scribe-document-delivery',
        )->execute($response)['message'];
    }

    private function targetFor(People $people, LeadCommunicationChannelEnum $channel): ?DeliveryTarget
    {
        $isEmail = $channel === LeadCommunicationChannelEnum::EMAIL;

        if (! $isEmail && ! $this->whatsAppConfigured($people)) {
            return null;
        }

        $contacts = $isEmail ? $people->getEmails() : $people->getCellPhones();

        foreach ($contacts as $contact) {
            $destination = Str::trimToNull((string) $contact->value);
            if ($destination !== null && ! ConsentGuard::isDestinationOptedOut($people, $destination, $isEmail)) {
                return new DeliveryTarget($channel, $destination);
            }
        }

        return null;
    }

    private function whatsAppConfigured(People $people): bool
    {
        return Communication::for($people->app, $people->company)->isReady(ChannelTypeEnum::WHATSAPP);
    }
}
