<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Services;

use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * VinSolutions answers a contact POST/PUT with a 400 (System.ArgumentException) when its own verifier
 * rejects an email, phone or address we sent — stricter than anything we can validate locally. That is
 * bad customer data, not a system fault, so we record it where the dealer can act on it (a note on the
 * lead + the log) instead of throwing and flooding Sentry with the same rejection on every retry.
 */
class ContactRejectionService
{
    public static function isDataRejection(ClientException $e): bool
    {
        return $e->getResponse()?->getStatusCode() === 400;
    }

    public static function reason(ClientException $e): string
    {
        $body = (string) $e->getResponse()?->getBody();
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? ($decoded['Message'] ?? null) : null;

        return trim((string) ($message ?? $body)) ?: 'VinSolutions rejected the contact data.';
    }

    public static function recordForPeople(People $people, ClientException $e): string
    {
        /** @var Lead|null $lead */
        $lead = $people->leads()->notDeleted()->first();

        return self::record(
            $lead,
            self::reason($e),
            [
                'people_id' => $people->getId(),
                'company_id' => $people->companies_id,
            ]
        );
    }

    public static function recordForLead(Lead $lead, ClientException $e): string
    {
        return self::record(
            $lead,
            self::reason($e),
            [
                'lead_id' => $lead->getId(),
                'people_id' => $lead->people_id,
                'company_id' => $lead->companies_id,
            ]
        );
    }

    private static function record(?Lead $lead, string $reason, array $context): string
    {
        /*   Log::warning(
              'VinSolutions rejected the contact data we pushed',
              $context + ['reason' => $reason]
          ); */

        if ($lead !== null) {
            new RecordLeadNoteAction($lead)->execute(
                'VinSolutions rejected this customer\'s contact information: ' . $reason
                . ' Fix the contact data on the record and the next sync will push it again.',
                'crm-sync-rejected'
            );
        }

        return $reason;
    }
}
