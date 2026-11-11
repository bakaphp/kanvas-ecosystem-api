<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Services;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum as WaSenderConfigurationEnum;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Actions\SendEmailToPeopleAction;
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
 * Carries an engagement link to a person. Email goes through SendEmailToPeopleAction (the agent's
 * mailbox when it has one, else the company's SMTP identity) with the PDFs attached; WhatsApp through
 * the company's WaSender number with the PDF as a document. Either way the sent text is filed on the
 * person's protocol channel, which is where their reply lands, so the agent reads both in one thread.
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

        $response = $target->channel === LeadCommunicationChannelEnum::EMAIL
            ? new SendEmailToPeopleAction($people)->execute(
                to: $target->destination,
                message: $body,
                subject: $subject,
                attachmentUrls: $urls,
                fromAgent: $agent,
                templateName: ScribeEmailTemplateEnum::DOCUMENT_DELIVERY->value,
            )
            : $this->sendWhatsApp(
                $people,
                $target->destination,
                $body,
                $urls,
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
        /** @var Companies $company */
        $company = $people->company;
        $key = WaSenderConfigurationEnum::API_KEY->value;

        return ! empty($company->get($key) ?? $people->app?->get($key));
    }

    /**
     * @param list<string> $documentUrls
     *
     * @return array<string, mixed>
     */
    private function sendWhatsApp(
        People $people,
        string $phone,
        string $body,
        array $documentUrls
    ): array {
        $service = new MessageService($people->app, $people->company);
        $response = $service->sendTextMessage($phone, $body);

        foreach ($documentUrls as $url) {
            $service->sendDocumentMessage($phone, $url);
        }

        return $response;
    }
}
