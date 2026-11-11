<?php

declare(strict_types=1);

namespace Tests\Scribe\Delivery;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalDispatchStatusEnum;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalEnum;
use Kanvas\Scribe\Delivery\Models\DocumentSignalDispatch;
use Kanvas\Scribe\Delivery\Services\DocumentEngagementSignalService;
use Kanvas\Scribe\Quotes\Models\Quote;

final class DocumentSignalDispatchTest extends DocumentDeliveryTestCase
{
    public function test_a_signal_with_no_agent_to_task_stays_retryable_and_the_retry_opens_the_task(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $quote = $this->draftQuote();
        $signals = new DocumentEngagementSignalService();

        $this->assertTrue($signals->raise($quote, DocumentSignalEnum::QUOTE_DECLINED, 'why they declined'));
        $this->assertFalse($signals->raise($quote, DocumentSignalEnum::QUOTE_DECLINED, 'why they declined'));

        $dispatch = $this->dispatchOf($quote);
        $this->assertSame(DocumentSignalDispatchStatusEnum::FAILED, $dispatch->status);
        $this->assertNull($dispatch->task_id);
        $this->assertSame(1, $dispatch->attempts);
        $this->assertNotNull($dispatch->next_retry_at);
        $this->assertNotNull($dispatch->last_error);
        $this->assertArrayNotHasKey('delivery_signals', $quote->refresh()->metadata ?? []);

        $agent = $this->receivablesAgent();
        $this->assertSame(0, $signals->retryDue($this->kanvasApp), 'Nothing is retried before its time.');

        Carbon::setTestNow($dispatch->next_retry_at->copy()->addMinute());
        $this->assertSame(1, $signals->retryDue($this->kanvasApp));

        $dispatch->refresh();
        $this->assertSame(DocumentSignalDispatchStatusEnum::DISPATCHED, $dispatch->status);
        $this->assertSame(2, $dispatch->attempts);
        $this->assertNull($dispatch->next_retry_at);
        $task = Task::query()->whereKey($dispatch->task_id)->first();
        $this->assertNotNull($task);
        $this->assertSame($agent->getId(), (int) $task->plan->agent_id);
    }

    public function test_a_signal_raised_twice_opens_one_task(): void
    {
        $this->receivablesAgent();
        $quote = $this->draftQuote();
        $signals = new DocumentEngagementSignalService();

        $this->assertTrue($signals->raise($quote, DocumentSignalEnum::NEVER_OPENED, 'a resend'));
        $this->assertFalse($signals->raise($quote, DocumentSignalEnum::NEVER_OPENED, 'a resend'));

        $dispatch = $this->dispatchOf($quote, DocumentSignalEnum::NEVER_OPENED);
        $this->assertSame(DocumentSignalDispatchStatusEnum::DISPATCHED, $dispatch->status, (string) $dispatch->last_error);
        $this->assertNotNull($dispatch->task_id);
        $this->assertSame(1, DocumentSignalDispatch::query()->where('document_id', $quote->getId())->where('document_type', 'quote')->count());
    }

    public function test_the_retry_command_runs_for_the_app(): void
    {
        $this->artisan('scribe:retry-document-signals', ['--app_id' => $this->kanvasApp->getId()])->assertSuccessful();
    }

    private function dispatchOf(Quote $quote, DocumentSignalEnum $signal = DocumentSignalEnum::QUOTE_DECLINED): DocumentSignalDispatch
    {
        return DocumentSignalDispatch::query()
            ->where('signal_key', DocumentSignalDispatch::keyFor($quote, $signal))
            ->firstOrFail();
    }

    private function receivablesAgent(): Agent
    {
        $type = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create(['provider' => 'neuron', 'handler' => AccountsReceivableAgent::class]);

        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'name' => 'Receivables ' . Str::random(6),
                'agent_type_id' => $type->getId(),
                'user_id' => static::$cachedUser->getId(),
                'is_active' => true,
            ]);
    }
}
