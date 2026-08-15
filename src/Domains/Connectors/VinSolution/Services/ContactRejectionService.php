<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Services;

use GuzzleHttp\Exception\ClientException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Two VinSolutions answers are facts about one customer record, not system faults, and there is
 * nothing we can do about either from our side:
 *  - 400 (System.ArgumentException): its verifier rejected an email/phone/address we sent.
 *  - 401 "User not authorized for Customer": the dealer user can't reach that contact.
 * Both are recorded as a private note on the lead so the dealer can act on them, and neither throws —
 * they were flooding Sentry with thousands of events nobody acts on. Any other 401 is a real
 * credential failure and still surfaces.
 */
class ContactRejectionService
{
    private const string UNAUTHORIZED_CONTACT = 'not authorized for customer';

    public static function isRecordRejection(ClientException $e): bool
    {
        return match ($e->getResponse()?->getStatusCode()) {
            400 => true,
            401 => self::isUnauthorizedContact($e),
            default => false,
        };
    }

    public static function reason(ClientException $e): string
    {
        $body = (string) $e->getResponse()?->getBody();
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? ($decoded['Message'] ?? null) : null;

        return trim((string) ($message ?? $body), " \t\n\r\0\x0B\"") ?: 'VinSolutions rejected the contact.';
    }

    public static function recordForPeople(People $people, ClientException $e): string
    {
        /** @var Lead|null $lead */
        $lead = $people->leads()->notDeleted()->first();

        return self::record($lead, $e);
    }

    public static function recordForLead(?Lead $lead, ClientException $e): string
    {
        return self::record($lead, $e);
    }

    private static function record(?Lead $lead, ClientException $e): string
    {
        $reason = self::reason($e);

        if ($lead !== null) {
            new RecordLeadNoteAction($lead)->execute(
                body: self::noteBody($e, $reason),
                tag: 'crm-sync-rejected',
                isPublic: false,
            );
        }

        return $reason;
    }

    private static function noteBody(ClientException $e, string $reason): string
    {
        if (self::isUnauthorizedContact($e)) {
            return 'VinSolutions did not authorize access to this customer record: ' . $reason
                . ' The assigned dealer user may not have permission over this contact in VinSolutions.';
        }

        return 'VinSolutions rejected this customer\'s contact information: ' . $reason
            . ' Fix the contact data on the record and the next sync will push it again.';
    }

    private static function isUnauthorizedContact(ClientException $e): bool
    {
        return $e->getResponse()?->getStatusCode() === 401
            && stripos((string) $e->getResponse()?->getBody(), self::UNAUTHORIZED_CONTACT) !== false;
    }
}
