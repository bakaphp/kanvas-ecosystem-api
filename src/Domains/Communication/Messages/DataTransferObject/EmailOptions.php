<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

/**
 * What only an email can carry. Ids and scalars, never models: it is stored on the request with the
 * rest of the content, so a retry composes the same email.
 */
final readonly class EmailOptions
{
    /**
     * @param list<string> $cc
     * @param string|null $template the company's email template the text is placed in; without one
     *                              the text goes out as written, which is how an agent's own mailbox
     *                              writes
     * @param array<string, mixed> $templateData extra variables the template reads, laid over the
     *                                           text and the person; anything JSON can hold, never a model
     * @param bool $companySignature the template closes with the company's own sign-off
     * @param int|null $signatureUserId the user whose personal signature closes the email
     * @param int|null $leadId the lead the email is about: the template's `lead`, and where an open
     *                         is noted
     * @param bool $trackOpens asks the provider to report when the email is read, when it can
     * @param bool $important marks the email high priority for the recipient's mail client
     */
    public function __construct(
        public array $cc = [],
        public ?string $template = null,
        public array $templateData = [],
        public bool $companySignature = false,
        public ?int $signatureUserId = null,
        public ?int $leadId = null,
        public ?int $campaignRecipientId = null,
        public bool $trackOpens = false,
        public bool $important = false
    ) {
    }

    public function toArray(): array
    {
        return [
            'cc' => $this->cc,
            'template' => $this->template,
            'template_data' => $this->templateData,
            'company_signature' => $this->companySignature,
            'signature_user_id' => $this->signatureUserId,
            'lead_id' => $this->leadId,
            'campaign_recipient_id' => $this->campaignRecipientId,
            'track_opens' => $this->trackOpens,
            'important' => $this->important,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            cc: array_values(array_filter((array) ($data['cc'] ?? []), is_string(...))),
            template: $data['template'] ?? null,
            templateData: (array) ($data['template_data'] ?? []),
            companySignature: (bool) ($data['company_signature'] ?? false),
            signatureUserId: isset($data['signature_user_id']) ? (int) $data['signature_user_id'] : null,
            leadId: isset($data['lead_id']) ? (int) $data['lead_id'] : null,
            campaignRecipientId: isset($data['campaign_recipient_id']) ? (int) $data['campaign_recipient_id'] : null,
            trackOpens: (bool) ($data['track_opens'] ?? false),
            important: (bool) ($data['important'] ?? false)
        );
    }
}
