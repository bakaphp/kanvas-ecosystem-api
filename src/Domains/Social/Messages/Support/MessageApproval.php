<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Support;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Contracts\AgentApprovalHandler;
use Kanvas\Social\Messages\Models\Message;

/**
 * The one owner of the approval-card shape on a message's JSON payload — a contract with the frontend,
 * not an internal detail, which is why it is not spelled by hand at call sites:
 *
 *     {
 *       "content":  "August '26 Highlights…",
 *       "from_ia":  true,
 *       "approval": { "kind": "email_draft", "status": "pending", "context": {} }
 *     }
 *
 * - `content` is what the card shows AND what pre-fills the editable draft.
 * - `approval.kind` is the discriminator the card switches its UI on. Unknown kinds fall through to a
 *   plain Approve/Reject, so a typo degrades silently instead of erroring — which is why kinds belong
 *   in a handler's `KIND` constant, never inline.
 * - `approval.status` must be EXACTLY `pending` to render as a card. That is what makes settle()
 *   load-bearing: an approved draft still saying `pending` keeps offering a live Approve button.
 * - `handler` is ours, not the frontend's, and optional — with none, approving ships the draft down
 *   the message's own channel verb.
 *
 * This payload is the RENDER contract, not the record. The decision lives in an `approval_requests`
 * row; this is the projection of it, written when the request opens and settled when it resolves.
 */
final class MessageApproval
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_APPROVED = 'approved';
    public const string STATUS_REJECTED = 'rejected';

    /**
     * One approval_type for every kind: the approver question is the same either way, and a type per
     * kind would need a policy row per kind per tenant. Per-kind rules go on a step's `payload.kind`.
     */
    public const string APPROVAL_TYPE = 'agent_message';

    /**
     * "Here is a mail I want to send, approve it." Shared rather than living on one handler's KIND,
     * because both the plain verb-routed send and a handler that does more (CustomerUpdateApprovalHandler
     * mails, then watermarks the account) want this same card.
     */
    public const string KIND_EMAIL_DRAFT = 'email_draft';

    /**
     * Make an already-posted message a pending approval card. The only way in, so a card cannot exist
     * without the lock that stops it shipping.
     *
     * `private` is a genuine per-caller choice: an agent asking to run an action it has not taken must
     * not sit in a public feed, while a held outbound reply stays public precisely so it shows up in
     * the reviewer's own feed, which is where they find it.
     *
     * @param class-string<AgentApprovalHandler>|null $handler
     * @param array<string, mixed> $context
     */
    public static function wrap(
        Message $message,
        string $kind,
        ?string $handler = null,
        array $context = [],
        bool $private = true
    ): Message {
        $message->addMessage(self::pendingPayload($kind, $handler, $context));

        if ($private) {
            $message->setPrivate();
        }

        $message->setLock();

        return $message;
    }

    /**
     * Record the outcome so the card stops presenting itself as a decision still to be made.
     */
    public static function settle(Message $message, string $status): void
    {
        $approval = self::payload($message);

        if ($approval === []) {
            return;
        }

        $approval['status'] = $status;
        $message->addMessage(['approval' => $approval]);
    }

    public static function status(Message $message): ?string
    {
        $status = self::payload($message)['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    public static function isPending(Message $message): bool
    {
        return self::status($message) === self::STATUS_PENDING;
    }

    public static function kind(Message $message): ?string
    {
        $kind = self::payload($message)['kind'] ?? null;

        return is_string($kind) ? $kind : null;
    }

    /**
     * @return class-string<AgentApprovalHandler>|null
     */
    public static function handler(Message $message): ?string
    {
        $class = self::payload($message)['handler'] ?? null;

        if (! is_string($class) || $class === '') {
            return null;
        }

        self::assertHandler($class);

        return $class;
    }

    /**
     * @return array<string, mixed>
     */
    public static function context(Message $message): array
    {
        return (array) (self::payload($message)['context'] ?? []);
    }

    /**
     * What the approval request freezes about the card. Read off the card rather than rebuilt from the
     * same inputs, so the two cannot disagree; `status` is deliberately absent, because the request's
     * own status column is the truth about where the decision stands.
     *
     * @return array<string, mixed>
     */
    public static function requestPayload(Message $message): array
    {
        return [
            'kind' => self::kind($message),
            'handler' => self::handler($message),
            'context' => self::context($message),
        ];
    }

    /**
     * Public for the caller that posts a message before gating it: a handler rejected after the post
     * leaves an ungated draft on a channel with nothing able to approve it.
     */
    public static function assertHandler(string $handler): void
    {
        if (! class_exists($handler) || ! is_subclass_of($handler, AgentApprovalHandler::class)) {
            throw new ValidationException(
                "Approval handler {$handler} must implement " . AgentApprovalHandler::class . '.',
            );
        }
    }

    /**
     * @param class-string<AgentApprovalHandler>|null $handler
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function pendingPayload(string $kind, ?string $handler, array $context): array
    {
        if ($handler !== null) {
            self::assertHandler($handler);
        }

        return [
            'from_ia' => true,
            'approval' => [
                'kind' => $kind,
                ...($handler !== null ? ['handler' => $handler] : []),
                'context' => $context,
                'status' => self::STATUS_PENDING,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(Message $message): array
    {
        $payload = $message->message;

        return is_array($payload) ? (array) ($payload['approval'] ?? []) : [];
    }
}
