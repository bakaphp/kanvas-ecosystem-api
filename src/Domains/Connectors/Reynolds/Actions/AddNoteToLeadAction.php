<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Carbon\Carbon;
use Kanvas\Connectors\Reynolds\Client;
use Kanvas\Connectors\Reynolds\DataTransferObject\Note as NoteData;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Enums\TransactionCodeEnum;
use Kanvas\Connectors\Reynolds\Exceptions\ReynoldsException;
use Kanvas\Connectors\Reynolds\Services\ApplicationAreaBuilder;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Pushes a standalone note onto an existing Reynolds prospect via the
 * Update Sales Lead (USL) Note sub-flow.
 *
 * If the Lead does not yet have a REYNOLDS_PROSPECT_ID set (i.e. it was
 * never inserted into Reynolds), this action falls back to PushLeadAction
 * to create the prospect first, then submits the note. This matches the
 * Elead "push lead then note" pattern.
 */
class AddNoteToLeadAction
{
    private const ROOT_ELEMENT = 'rey_SalesAssistCRMUpdateSalesLead';

    public function __construct(
        protected Lead $lead,
        protected string $noteContent,
    ) {
    }

    public function execute(): array
    {
        $note = trim($this->noteContent);
        if ($note === '') {
            throw new ReynoldsException('Cannot push an empty note to Reynolds');
        }

        $prospectId = $this->ensureProspectId();

        $client = new Client($this->lead->app, $this->lead->company);
        $now = Carbon::now();

        $payload = [
            'ApplicationArea' => ApplicationAreaBuilder::build(
                $client,
                TransactionCodeEnum::UPDATE_SALES_LEAD,
                'I'
            ),
            'Record' => [
                'Identifier' => [
                    'ProspectId' => (string) $prospectId,
                ],
                'Note' => new NoteData(
                    prospectNote: $note,
                    noteInsertedOn: $now,
                )->toRecord(),
            ],
        ];

        $response = $client->processMessage(self::ROOT_ELEMENT, $payload);

        // R&R can return <ActivityId/> (empty self-closing) which the XML parser
        // resolves to an empty array rather than null. Normalize to ?string.
        $activityIdRaw = $response['TransStatus']['ActivityId'] ?? null;
        $activityId = is_scalar($activityIdRaw) ? (string) $activityIdRaw : null;

        return [
            'activity_id' => $activityId,
            'prospect_id' => (string) $prospectId,
            'note' => $note,
            'response' => $response,
        ];
    }

    /**
     * Reynolds USL requires a ProspectId. If the Lead has not been pushed
     * to Reynolds yet, push it first so we have one — same flow as Elead's
     * SyncLeadAction does for any operation that needs the opportunity to
     * already exist.
     */
    private function ensureProspectId(): string
    {
        $existing = $this->lead->get(CustomFieldEnum::PROSPECT_ID->value);
        if (! empty($existing)) {
            return (string) $existing;
        }

        $pushResult = new PushLeadAction($this->lead)->execute();
        $prospectId = $pushResult['prospect_id'] ?? null;

        if (empty($prospectId)) {
            throw new ReynoldsException(
                'Lead has no REYNOLDS_PROSPECT_ID and PushLeadAction did not return one'
            );
        }

        return (string) $prospectId;
    }
}
