<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Summary\Actions\UpdateLeadSummaryAction;
use Kanvas\Guild\Leads\Summary\LeadSummaryProgress;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Tests\Stubs\Intelligence\ScriptedStructuredNeuronProvider;

/**
 * A lead in the test app, owned by the acting user, with messages on it, and the structured answer a
 * scripted summary model gives back.
 */
trait MakesLeadConversations
{
    protected function makeLead(): Lead
    {
        $people = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->withUserId(auth()->user()->getId())
            ->create();

        return Lead::factory()
            ->withAppId($people->apps_id)
            ->withCompanyId($people->companies_id)
            ->withPeopleId($people->getId())
            ->create(['leads_status_id' => 1, 'leads_owner_id' => auth()->user()->getId()]);
    }

    /**
     * @param bool $attach false leaves the message in the lead's tenant but off the lead, to attach later
     */
    protected function leadMessage(
        Lead $lead,
        string $content,
        bool $fromAgent = false,
        bool $attach = true
    ): Message {
        $message = Message::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->withMessageType(MessageTypeService::getOrCreate($lead->app, $fromAgent ? 'agent' : 'user'))
            ->create(['message' => ['content' => $content, 'from_ia' => $fromAgent]]);

        if ($attach) {
            $message->addEntity($lead);
        }

        return $message;
    }

    /**
     * One summary step with the turn's budget, the model scripted to answer `$responses` in order.
     *
     * @param list<string> $responses
     */
    protected function updateSummary(Lead $lead, array $responses): LeadSummaryProgress
    {
        return new UpdateLeadSummaryAction(
            $lead,
            new ScriptedStructuredNeuronProvider($responses),
            microtime(true) + 15,
        )->execute();
    }

    /**
     * @param list<array<string, mixed>> $operations citations given as plain message ids
     */
    protected function summaryAnswer(array $operations, string $currentState = 'talking'): string
    {
        return (string) json_encode([
            'sections' => ['current_state' => $currentState],
            'operations' => array_map(
                static fn (array $operation): array => [
                    ...$operation,
                    'citations' => array_map(static fn (int $id): array => ['message_id' => $id], $operation['citations']),
                ],
                $operations,
            ),
        ]);
    }
}
