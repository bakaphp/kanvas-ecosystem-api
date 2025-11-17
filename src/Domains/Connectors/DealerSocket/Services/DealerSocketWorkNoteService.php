<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\DealerSocket\WorkNoteClient;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Regions\Models\Regions;
use Throwable;

class DealerSocketWorkNoteService
{
    public WorkNoteClient $workNoteClient;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
    ) {
        $this->workNoteClient = new WorkNoteClient(app: $app, company: $company, region: $region);
    }

    /**
     * Add a WorkNote to a Lead
     *
     * @param Lead $lead The lead to add the note to
     * @param string $note The note text (supports HTML, up to 255 chars)
     * @return array Response from DealerSocket
     */
    public function addNoteToLead(Lead $lead, string $note): array
    {
        try {
            $workNoteData = $this->buildWorkNoteData($lead, $note);

            $response = $this->workNoteClient->insertWorkNote($workNoteData);

            if (! $response['success']) {
                throw new Exception(
                    $response['errorMessage'] ?? $response['error'] ?? 'Failed to insert WorkNote'
                );
            }

            return $response;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * Add a simple text note
     */
    public function addSimpleNote(Lead $lead, string $noteText): array
    {
        return $this->addNoteToLead($lead, $noteText);
    }

    /**
     * Add an HTML formatted note
     */
    public function addHtmlNote(Lead $lead, string $htmlNote): array
    {
        return $this->addNoteToLead($lead, $htmlNote);
    }

    /**
     * Add a note with timestamp
     */
    public function addTimestampedNote(Lead $lead, string $noteText): array
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $htmlNote = "<span style='color:#333'>[{$timestamp}] {$noteText}</span>";

        return $this->addNoteToLead($lead, $htmlNote);
    }

    /**
     * Add a colored/styled note
     */
    public function addStyledNote(Lead $lead, string $noteText, string $color = 'blue'): array
    {
        $htmlNote = "<span style='color:{$color}'>{$noteText}</span>";

        return $this->addNoteToLead($lead, $htmlNote);
    }

    /**
     * Build WorkNote data from Lead
     */
    protected function buildWorkNoteData(Lead $lead, string $note): array
    {
        $entityId = $lead->people->get(
            DealerSocketConfigurationService::getCustomerIdKey($lead->people, $this->region)
        );

        if (! $entityId) {
            throw new Exception('Customer does not have a DealerSocket Entity ID. Please create customer first.');
        }

        $eventId = $lead->get(
            DealerSocketConfigurationService::getLeadIdKey($lead, $this->region)
        );

        if (! $eventId) {
            throw new Exception('Lead does not have a DealerSocket Event ID. Please create lead first.');
        }

        // Validate note length (max 255 chars)
        if (strlen($note) > 255) {
            $note = substr($note, 0, 255);
        }

        return [
            'entityId' => (int) $entityId,
            'eventId' => (int) $eventId,
            'batchId' => 0, // Usually 0 for most vendors
            'note' => $note,
        ];
    }
}
