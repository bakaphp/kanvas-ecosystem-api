<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\CustomerSuccess;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Actions\RecordOrganizationNoteAction;
use Kanvas\Intelligence\Agents\Approvals\CustomerUpdateApprovalHandler;
use Kanvas\Intelligence\Agents\DataTransferObject\CustomerUpdateDraft;
use Kanvas\Intelligence\Agents\Services\CustomerSuccess\CustomerUpdateRenderer;
use Kanvas\Social\Messages\Actions\RequestMessageApprovalAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Users\Models\Users;

/**
 * Puts a drafted update in front of a human: the copy lands in the account's notes thread as a locked
 * approval card, and nothing is mailed until someone approves it.
 *
 * The note is the record either way — it is what next month's draft reads to know what this account has
 * already been told. Requesting approval writes it; approving sends it and advances the watermark.
 */
class RequestCustomerUpdateApprovalAction
{
    /**
     * @param list<string> $recipients everyone this account's update goes to. A list rather than one
     *                                 address because the audience is "the people on this account
     *                                 tagged newsletter", which is normally more than one.
     */
    public function __construct(
        private readonly CustomerUpdateDraft $draft,
        private readonly Users $requestedBy,
        private readonly array $recipients,
    ) {
    }

    public function execute(): ?Message
    {
        // Ahead of the note, not at approve time: a card with nobody to send to is one a human has to
        // find and delete, and the approver only discovers it when they press the button.
        if ($this->recipients === []) {
            throw new ValidationException('Cannot request approval for an update with no recipient.');
        }

        $note = new RecordOrganizationNoteAction($this->draft->organization)->execute(
            body: new CustomerUpdateRenderer()->toMarkdown($this->draft),
            tag: 'newsletter',
            actingUser: $this->requestedBy,
            fromIa: true,
            isPublic: false,
        );

        if ($note === null) {
            return null;
        }

        // The first recipient rides on the message as chat_jid, the way every other email draft
        // carries its destination, so anything SendAgentEmailAction-shaped still finds one. The full
        // list lives in the approval context, which is what the handler actually sends to.
        $note->addMessage(['chat_jid' => $this->recipients[0]]);

        new RequestMessageApprovalAction(
            message: $note,
            kind: MessageApproval::KIND_EMAIL_DRAFT,
            handler: CustomerUpdateApprovalHandler::class,
            context: [
                'organization_id' => $this->draft->organization->getId(),
                'subject' => $this->draft->subject,
                'recipients' => $this->recipients,
                'covered_through' => $this->draft->coveredThrough?->toIso8601String(),
                'release_tags' => $this->draft->releaseTags,
            ],
        )->execute();

        return $note->refresh();
    }
}
