<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesRevenueTool;
use Tests\TestCase;

/**
 * The behaviour under test is prose the model reads, so the assertions are about wording as much as
 * structure. A refactor that keeps `outcome` but drops "do NOT retry" has removed the whole point.
 */
class ToolOutcomeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_noop_guidance_tells_the_model_the_answer_is_final(): void
    {
        $guidance = ToolOutcomeEnum::NOOP->guidance();

        $this->assertStringContainsString('do NOT retry', $guidance);
        $this->assertStringContainsString('same arguments returns the same', $guidance);
    }

    public function test_only_transient_outcomes_are_retryable(): void
    {
        $this->assertTrue(ToolOutcomeEnum::TIMEOUT->isRetryable());
        $this->assertTrue(ToolOutcomeEnum::PROVIDER_ERROR->isRetryable());

        $this->assertFalse(ToolOutcomeEnum::NOOP->isRetryable());
        $this->assertFalse(ToolOutcomeEnum::NOT_FOUND->isRetryable());
        $this->assertFalse(ToolOutcomeEnum::DENIED->isRetryable());
        $this->assertFalse(ToolOutcomeEnum::INVALID_ARGS->isRetryable());
    }

    /** The KANVAS-ECOSYSTEM-682 shape: a correct zero that used to read as a failed call. */
    public function test_sales_revenue_reports_an_empty_range_as_noop(): void
    {
        [$app, $company] = $this->context();

        $result = new SalesRevenueTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(since: '1970-01-01', until: '1970-01-02');

        $this->assertSame(0, $result['orders']);
        $this->assertSame(ToolOutcomeEnum::NOOP->value, $result['outcome']);
        $this->assertStringContainsString('do NOT retry', $result['note']);
    }

    /** The override has to survive alongside the generic sentence, not replace it. */
    public function test_sales_revenue_keeps_its_specific_guidance_as_well_as_the_generic(): void
    {
        [$app, $company] = $this->context();

        $result = new SalesRevenueTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(since: '1970-01-01', until: '1970-01-02');

        $this->assertStringContainsString('first_booked_order_date', $result['note']);
        $this->assertArrayHasKey('first_booked_order_date', $result);
    }

    /** NOT_FOUND, not NOOP — a different name genuinely might match, so the advice differs. */
    public function test_find_customer_reports_no_match_as_not_found(): void
    {
        [$app, $company] = $this->context();

        $result = new FindCustomerTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(name: 'zzqq-no-such-customer-anywhere');

        $this->assertSame(0, $result['count']);
        $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
        $this->assertStringContainsString('search differently', $result['note']);
    }

    /**
     * @return array{0: Apps, 1: Companies}
     */
    private function context(): array
    {
        return [app(Apps::class), static::$cachedUser->getCurrentCompany()];
    }
}
