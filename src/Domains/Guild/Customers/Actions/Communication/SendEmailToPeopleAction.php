<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions\Communication;

use Baka\Support\Str;
use Kanvas\Communication\Channels\Email\EmailComposer;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Messages\DataTransferObject\Attachment;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\EmailOptions;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
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
 * A send that is refused or skipped throws, which every caller treats as "not sent". The send
 * itself is SendMessageToPeopleAction's, the one place a person is written to on any channel.
 */
class SendEmailToPeopleAction
{
    public function __construct(protected readonly People $people)
    {
    }

    /**
     * @param  list<string>  $attachmentUrls
     * @param  list<string>  $cc  already filtered by the caller; this action sends to whatever it is given
     * @param  array<string, mixed>  $variables  Extra template context laid over `content` and `people`;
     *     the company and the signature keys stay the sender's own.
     * @param  bool  $trackOpens  Off for a test send to a teammate, so their open never lands on the person.
     * @param  bool  $writtenByOwner  The signature owner wrote the email themselves, so their own signature closes it.
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
        bool $writtenByOwner = false,
    ): array {
        $content = $this->compose(
            message: $message,
            subject: $subject ?? 'Message from ' . $this->people->company->name,
            attachments: $attachmentUrls,
            template: Str::trimToNull($templateName) ?? EmailComposer::DEFAULT_TEMPLATE,
            options: new EmailOptions(
                cc: $cc,
                templateData: $variables,
                campaignRecipientId: $campaignRecipient?->getId(),
                trackOpens: $trackOpens,
                important: $important
            ),
            fromAgent: $fromAgent,
            signatureOwner: $signatureOwner,
            writtenByOwner: $writtenByOwner
        );

        $sent = new SendMessageToPeopleAction($this->people)->send(
            ChannelTypeEnum::EMAIL,
            $to,
            $content,
            Sender::speaking($fromAgent, $signatureOwner)
        );

        return ['template' => $content->email->template ?? 'agent-mailbox'] + $sent;
    }

    /**
     * The email as it leaves, for every email to a person, a lead's included. From an agent's own
     * mailbox the text goes out as written; from any other address it is placed in the company's
     * template. The owner's signature closes it when the speaker may sign with it; otherwise the
     * company's own sign-off does, when the caller asks for one (`$options->companySignature`).
     *
     * @param iterable<Attachment|string> $attachments
     * @param EmailOptions $options what the caller knows: copies, template variables, the lead or
     *                              campaign recipient it is about, tracking. The template and the
     *                              signature are decided here.
     */
    public function compose(
        string $message,
        string $subject,
        iterable $attachments,
        string $template,
        EmailOptions $options,
        ?Agent $fromAgent = null,
        ?Users $signatureOwner = null,
        bool $writtenByOwner = false
    ): Content {
        // A person who wrote the email signs it themselves; no agent's signing grant is involved.
        $ownerSignature = $writtenByOwner && $fromAgent === null
            ? UserEmailSignature::fromUser($signatureOwner, $this->people->company)
            : UserEmailSignature::forSender($signatureOwner, $this->people->company, $fromAgent);
        $writesAsItself = Communication::for($this->people->app, $this->people->company)
            ->speaksFromOwnAddress(ChannelTypeEnum::EMAIL, $fromAgent);
        $template = $writesAsItself ? null : $template;

        return Content::text($message)
            ->withSubject($subject)
            ->withAttachments($attachments)
            ->withEmail(new EmailOptions(
                cc: $options->cc,
                template: $template,
                templateData: [...$options->templateData, 'noHi' => true],
                companySignature: $template !== null && $options->companySignature && $ownerSignature === null,
                signatureUserId: $ownerSignature !== null ? $signatureOwner?->getId() : null,
                leadId: $options->leadId,
                campaignRecipientId: $options->campaignRecipientId,
                trackOpens: $options->trackOpens,
                important: $options->important
            ));
    }
}
