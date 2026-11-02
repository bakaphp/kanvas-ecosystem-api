<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Exceptions;

use Baka\Exceptions\LightHouseCustomException;
use Kanvas\Connectors\Reynolds\Enums\CrmTypeEnum;
use Override;

/**
 * Reaches the client as `extensions.reason` (the code) and `extensions.field`.
 */
class CrmLeadSyncException extends LightHouseCustomException
{
    public const string CRM_NOT_CONFIGURED = 'crm_not_configured';
    public const string CLIENT_LEAD_MISMATCH = 'client_lead_mismatch';
    public const string OWNER_NOT_FOUND = 'owner_not_found';
    public const string FOLLOWER_NOT_FOUND = 'follower_not_found';
    public const string INVALID_PARTICIPANT = 'invalid_participant';
    public const string LOCKED_RETRY = 'locked_retry';
    public const string VALIDATION_FAILED = 'validation_failed';

    public function __construct(string $message, string $reason, protected ?string $field = null)
    {
        parent::__construct($message, $reason);
    }

    public static function notConfigured(CrmTypeEnum $type): self
    {
        return new self(
            'This company is not configured for ' . $type->value,
            self::CRM_NOT_CONFIGURED
        );
    }

    public static function clientLeadMismatch(): self
    {
        return new self(
            'The lead belongs to a customer with a different client id',
            self::CLIENT_LEAD_MISMATCH,
            'identity.client_id'
        );
    }

    public static function ownerNotFound(?string $name): self
    {
        return new self(
            'No user of this company matches the salesperson ' . ($name ?? '(none)'),
            self::OWNER_NOT_FOUND,
            'lead.salesperson'
        );
    }

    public static function followerNotFound(string $email, string $field): self
    {
        return new self('No user of this company has the email ' . $email, self::FOLLOWER_NOT_FOUND, $field);
    }

    public static function invalidParticipant(string $message, string $field): self
    {
        return new self($message, self::INVALID_PARTICIPANT, $field);
    }

    public static function lockedRetry(): self
    {
        return new self('This customer is being synced right now, retry in a moment', self::LOCKED_RETRY);
    }

    public static function validationFailed(string $message, string $field): self
    {
        return new self($message, self::VALIDATION_FAILED, $field);
    }

    /**
     * Only the errors that stop a create the dealer expected to happen go to Sentry; the rest are the
     * caller's to fix and would only be noise.
     */
    public function shouldReport(): bool
    {
        return in_array($this->reason, [self::OWNER_NOT_FOUND, self::VALIDATION_FAILED], true);
    }

    #[Override]
    public function getExtensions(): array
    {
        return [
            'reason' => $this->reason,
            'field' => $this->field,
        ];
    }
}
