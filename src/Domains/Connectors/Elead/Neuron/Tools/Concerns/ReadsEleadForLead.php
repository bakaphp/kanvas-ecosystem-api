<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Neuron\Tools\Concerns;

use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Elead\Entities\Lead as EleadOpportunity;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Throwable;

/**
 * Shared ground for the eLead tools: the lead's company must have eLead connected and the lead must
 * already be an opportunity there. Both misses are ordinary business conditions and come back as
 * structured outcomes, never as throws into the chat.
 *
 * The eLead reads sit behind protected methods because the entities build their HTTP client
 * inline; a test answers them in-process by extending the tool.
 */
trait ReadsEleadForLead
{
    use ReportsToolOutcome;
    use ResolvesLeadForTool;

    /**
     * @return Lead|array<string, mixed>
     */
    protected function resolveEleadLeadOrError(int $leadId): Lead|array
    {
        $lead = $this->resolveLeadOrError($leadId);
        if (is_array($lead)) {
            return $lead;
        }

        if (empty($lead->company->get(CustomFieldEnum::COMPANY->value))) {
            return $this->denied(
                'eLead is not connected for this company, so there is nothing to read from it.',
                ['lead_id' => $lead->getId()],
                guidance: 'An admin connects eLead from the integrations settings.'
            );
        }

        if ($this->opportunityId($lead) === '') {
            return $this->notFound(
                ['lead_id' => $lead->getId()],
                guidance: 'This lead has no eLead opportunity yet, so eLead holds nothing for it. It gets '
                    . 'one when the lead is pushed to eLead; nothing you can call creates it.'
            );
        }

        return $lead;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function customerSurfaceRefusal(string $subject): ?array
    {
        if (! $this->isCustomerSurface()) {
            return null;
        }

        return $this->denied(
            $subject . ' holds the dealership\'s internal notes and is not available in a customer conversation.',
            guidance: 'Answer the customer from what you already have, or offer to have a salesperson confirm.'
        );
    }

    protected function opportunityId(Lead $lead): string
    {
        return (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value);
    }

    protected function timezone(Lead $lead): string
    {
        return $lead->company->getTimezone() ?? 'UTC';
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentActivity(SalesActivities $activity, string $timezone): array
    {
        return [
            'activity_id' => $activity->id,
            'type' => $activity->activityType,
            'category' => $activity->category,
            'name' => $activity->name,
            'due_at' => $this->localize($activity->dueDate, $timezone),
            'completed_at' => $this->localize($activity->completedDate, $timezone),
            'closed_at' => $this->localize($activity->closedDate, $timezone),
            'is_open' => $activity->completedDate === null && $activity->closedDate === null,
            'outcome' => $activity->outcome,
            'assigned_to' => $activity->assignedTo,
            'completed_by' => $activity->completedBy,
            'created_by' => $activity->createdBy,
        ];
    }

    protected function localize(?string $date, string $timezone): ?string
    {
        return $date !== null ? Carbon::parse($date)->setTimezone($timezone)->toIso8601String() : null;
    }

    /**
     * A 404 is eLead saying the opportunity is gone, which the model can tell the user; anything
     * else is an upstream fault worth a Sentry event and a plain "it failed".
     *
     * @return array<string, mixed>
     */
    protected function eleadFailure(Throwable $e, Lead $lead): array
    {
        $payload = [
            'lead_id' => $lead->getId(),
            'elead_opportunity_id' => $this->opportunityId($lead),
        ];

        if ($e instanceof ClientException && $e->getResponse()->getStatusCode() === 404) {
            return $this->notFound($payload, guidance: 'eLead no longer has this opportunity.');
        }

        report($e);

        return $this->failed('eLead did not answer this request (' . class_basename($e) . ').', $payload);
    }

    /**
     * @return SalesActivities[]
     */
    protected function fetchActivities(
        Lead $lead,
        Carbon $from,
        Carbon $to,
        bool $openOnly
    ): array {
        return SalesActivities::getActivitiesByOpportunityId(
            $lead->app,
            $lead->company,
            $this->opportunityId($lead),
            $from,
            $to,
            $openOnly
        );
    }

    protected function fetchOpportunity(Lead $lead): EleadOpportunity
    {
        return EleadOpportunity::getById(
            $lead->app,
            $lead->company,
            $this->opportunityId($lead),
            fresh: true
        );
    }
}
