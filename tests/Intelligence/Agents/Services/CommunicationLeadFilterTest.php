<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Services;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Services\LeadTraits\CommunicationLeadFilter;
use Tests\TestCase;

class CommunicationLeadFilterTest extends TestCase
{
    public function testAwaitingResponseUsesLatestCustomerMessageAndAge(): void
    {
        $company = Companies::factory()->create();
        $waiting = $this->lead($company);
        $responded = $this->lead($company);
        $filter = $this->filter(
            latest: collect([
                $this->message($waiting->getId(), 10, 'contact', now()->subDays(8)->toDateTimeString()),
                $this->message($responded->getId(), 20, 'user', now()->subDays(9)->toDateTimeString()),
            ]),
        );
        $query = Lead::query()->whereIn('id', [$waiting->getId(), $responded->getId()]);

        $context = $filter->apply($query, app(Apps::class), $company, [
            'communication_state' => 'awaiting_team_response',
            'customer_waiting_since_days' => 7,
        ]);

        $this->assertSame([$waiting->getId()], $query->pluck('id')->all());
        $this->assertSame('contact', $context['last_messages'][$waiting->getId()]['sender_type']);
    }

    public function testNeverRepliedRequiresOutboundAndNoCustomerMessage(): void
    {
        $company = Companies::factory()->create();
        $silent = $this->lead($company);
        $replied = $this->lead($company);
        $filter = $this->filter(
            latest: collect([
                $this->message($silent->getId(), 30, 'agent', now()->toDateTimeString()),
                $this->message($replied->getId(), 40, 'user', now()->toDateTimeString()),
            ]),
            summaries: collect([
                (object) ['lead_id' => $silent->getId(), 'has_contact' => 0, 'has_outbound' => 1],
                (object) ['lead_id' => $replied->getId(), 'has_contact' => 1, 'has_outbound' => 1],
            ]),
        );
        $query = Lead::query()->whereIn('id', [$silent->getId(), $replied->getId()]);

        $filter->apply($query, app(Apps::class), $company, ['communication_state' => 'never_replied']);

        $this->assertSame([$silent->getId()], $query->pluck('id')->all());
    }

    public function testNoMessagesExcludesEveryLeadWithCommunication(): void
    {
        $company = Companies::factory()->create();
        $withMessage = $this->lead($company);
        $withoutMessage = $this->lead($company);
        $filter = $this->filter(collect([
            $this->message($withMessage->getId(), 50, 'contact', now()->toDateTimeString()),
        ]));
        $query = Lead::query()->whereIn('id', [$withMessage->getId(), $withoutMessage->getId()]);

        $filter->apply($query, app(Apps::class), $company, ['communication_state' => 'no_messages']);

        $this->assertSame([$withoutMessage->getId()], $query->pluck('id')->all());
    }

    private function filter(Collection $latest, ?Collection $summaries = null): CommunicationLeadFilter
    {
        return new class ($latest, $summaries ?? collect()) extends CommunicationLeadFilter {
            public function __construct(
                private readonly Collection $latest,
                private readonly Collection $summaries,
            ) {
            }

            protected function latestMessages(Apps $app, Companies $company): Collection
            {
                return $this->latest;
            }

            protected function conversationSummaries(Apps $app, Companies $company): Collection
            {
                return $this->summaries;
            }
        };
    }

    private function lead(Companies $company): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create();
    }

    private function message(int $leadId, int $messageId, string $senderType, string $createdAt): object
    {
        return (object) [
            'lead_id' => $leadId,
            'message_id' => $messageId,
            'sender_type' => $senderType,
            'created_at' => $createdAt,
        ];
    }
}
