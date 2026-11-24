<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Kanvas\Scribe\DocumentSequences\Exceptions\DocumentSequenceUnavailableException;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ConfigureEcfRangeAction;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalNumberRange;
use RuntimeException;
use Tests\Stubs\Scribe\FakeEcfProvider;

/**
 * A range DGII authorized can be partly used outside Kanvas (a manual test, another POS). Loading it asks the
 * provider which numbers it already holds and starts issuing after them, keeping the range itself as given.
 */
class ConfigureEcfRangeProviderCheckTest extends EcfTestCase
{
    public function test_issuing_starts_after_the_numbers_the_provider_already_holds(): void
    {
        FakeEcfProvider::$issuedNumbers = $this->encfs(4, 1004);

        $action = $this->action('E330000000004', 'E330000005000');
        $sequence = $action->execute();

        $this->assertSame(1005, (int) $sequence->next_value);
        $this->assertSame(4, (int) $sequence->range_start);
        $this->assertSame('E330000000004', FiscalNumberRange::query()->whereKey($sequence->fiscal_number_range_id)->first()->firstNumber());
        $this->assertStringContainsString('E330000000004 to E330000001004 are already issued', (string) $action->notice);
        $this->assertStringContainsString('starts at E330000001005', (string) $action->notice);
        $this->assertLessThan(30, count(FakeEcfProvider::$issuedLookups), 'a galloping search, not one lookup per number');
    }

    public function test_an_untouched_range_costs_one_lookup_and_says_nothing(): void
    {
        $action = $this->action('4', '1000');
        $sequence = $action->execute();

        $this->assertSame(4, (int) $sequence->next_value);
        $this->assertNull($action->notice);
        $this->assertSame(['E330000000004'], FakeEcfProvider::$issuedLookups);
    }

    public function test_a_range_the_provider_used_up_is_refused(): void
    {
        FakeEcfProvider::$issuedNumbers = $this->encfs(4, 20);

        $this->expectException(DocumentSequenceUnavailableException::class);

        $this->action('4', '20')->execute();
    }

    public function test_a_provider_that_cannot_answer_never_blocks_the_load(): void
    {
        FakeEcfProvider::$issuedLookupException = new RuntimeException('provider down');

        $action = $this->action('4', '1000');
        $sequence = $action->execute();

        $this->assertSame(4, (int) $sequence->next_value);
        $this->assertStringContainsString('Could not check the provider', (string) $action->notice);
    }

    private function action(string $start, string $end): ConfigureEcfRangeAction
    {
        return new ConfigureEcfRangeAction(
            app: $this->kanvasApp,
            company: $this->company,
            type: '33',
            start: $start,
            end: $end,
        );
    }

    /**
     * @return list<string>
     */
    private function encfs(int $from, int $to): array
    {
        return array_map(fn (int $n): string => 'E33' . str_pad((string) $n, 10, '0', STR_PAD_LEFT), range($from, $to));
    }
}
