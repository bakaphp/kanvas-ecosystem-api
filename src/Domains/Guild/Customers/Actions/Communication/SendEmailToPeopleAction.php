<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions\Communication;

use Baka\Support\Str;
use Kanvas\Communication\Channels\Email\EmailComposer;
use Kanvas\Communication\Communication;
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
        $subject ??= 'Message from ' . $this->people->company->name;
        $ownerSignature = UserEmailSignature::forSender($signatureOwner, $this->people->company, $fromAgent);
        $writesAsItself = Communication::for($this->people->app, $this->people->company)
            ->speaksFromOwnAddress(ChannelTypeEnum::EMAIL, $fromAgent);

        // An agent's own mailbox writes the text as it is; any other address sends the company's
        // templated email.
        $template = $writesAsItself
            ? null
            : (Str::trimToNull($templateName) ?? EmailComposer::DEFAULT_TEMPLATE);

        $content = Content::text($message)
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
            ));

        $sent = new SendMessageToPeopleAction($this->people)->send(
            ChannelTypeEnum::EMAIL,
            $to,
            $content,
            Sender::speaking($fromAgent, $signatureOwner)
        );

        return ['template' => $template ?? 'agent-mailbox'] + $sent;
    }
}
