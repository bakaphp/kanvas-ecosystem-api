<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Baka\Support\Str;
use Kanvas\Communication\Channels\Email\EmailComposer;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\EmailOptions;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Users\Models\Users;

/**
 * An email to a person with no lead behind them (a campaign recipient). It leaves the way a lead's
 * email does: from the agent's own mailbox when that is a connection (the text as written), else
 * on the company's email connection, in the template the caller names or the default one.
 *
 * A send that is refused or skipped throws, which every caller treats as "not sent".
 */
class SendEmailToPeopleAction
{
    public function __construct(
        protected readonly People $people,
    ) {
    }

    /**
     * @param  list<string>  $attachmentUrls
     * @param  list<string>  $cc  already filtered by the caller; this action sends to whatever it is given
     * @param  array<string, mixed>  $variables  Extra template context laid over `content` and `people`;
     *     the company and the signature keys stay the sender's own.
     * @param  bool  $trackOpens  Off for a test send to a teammate, so their open never lands on the person.
     */
    public function execute(
        string $to,
        string $message,
        ?string $subject = null,
        array $attachmentUrls = [],
        ?string $templateName = null,
        bool $important = false,
        ?Agent $fromAgent = null,
        ?Users $signatureOwner = null,
        array $cc = [],
        ?CampaignRecipient $campaignRecipient = null,
        array $variables = [],
        bool $trackOpens = true,
    ): array {
        if (trim($to) === '') {
            throw new ValidationException('People has no email address to send to');
        }

        $subject ??= 'Message from ' . $this->people->company->name;
        $ownerSignature = UserEmailSignature::forSender($signatureOwner, $this->people->company, $fromAgent);
        $communication = Communication::for($this->people->app, $this->people->company);
        $writesAsItself = $communication->speaksFromOwnAddress(ChannelTypeEnum::EMAIL, $fromAgent);

        // An agent's own mailbox writes the text as it is; any other address sends the company's
        // templated email.
        $template = $writesAsItself
            ? null
            : (Str::trimToNull($templateName) ?? EmailComposer::DEFAULT_TEMPLATE);

        $delivery = $communication
            ->send(
                $this->people,
                Content::text($message)
                    ->withSubject($subject)
                    ->withAttachments($attachmentUrls)
                    ->withEmail(new EmailOptions(
                        cc: $cc,
                        template: $template,
                        templateData: [...$variables, 'noHi' => true],
                        signatureUserId: $ownerSignature !== null ? $signatureOwner?->getId() : null,
                        campaignRecipientId: $campaignRecipient?->getId(),
                        trackOpens: $trackOpens,
                        important: $important
                    )),
                channels: [ChannelTypeEnum::EMAIL->value],
                from: Sender::speaking($fromAgent, $signatureOwner),
                sync: true,
                toAddress: $to,
                file: false
            )
            ->delivery(ChannelTypeEnum::EMAIL);

        if ($delivery?->wasAccepted() !== true) {
            throw new ValidationException(
                'The email was not sent: ' . ($delivery?->error ?? $delivery?->error_code ?? 'the company has no email address to send from')
            );
        }

        return [
            'channel' => 'email',
            'to' => $to,
            'template' => $template ?? 'agent-mailbox',
            'from' => $delivery->connection?->account_key,
            'people_id' => $this->people->getId(),
            'provider' => $delivery->provider,
            'provider_message_id' => $delivery->external_id,
            'operation_id' => $delivery->request_id,
        ];
    }
}
