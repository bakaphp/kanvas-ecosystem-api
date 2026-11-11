<?php

declare(strict_types=1);

namespace Tests\Scribe\DocumentSequences;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Exceptions\DocumentSequenceUnavailableException;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Tests\TestCase;

class EcfSequenceAllocationTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private const int APPS_ID = 1;
    private const int COMPANY_ID = 987001;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_range_hands_out_ten_digit_e_ncfs_from_its_start(): void
    {
        $this->configure(start: 4, end: 1000);

        $allocator = new DocumentNumberAllocatorService();

        $this->assertSame('E310000000004', $this->allocate($allocator));
        $this->assertSame('E310000000005', $this->allocate($allocator));
    }

    public function test_an_e_cf_type_without_a_configured_range_is_refused_instead_of_starting_at_one(): void
    {
        $this->expectException(DocumentSequenceUnavailableException::class);
        $this->expectExceptionMessage('No authorized number range');

        $this->allocate(new DocumentNumberAllocatorService());
    }

    public function test_an_exhausted_range_refuses_before_consuming_anything(): void
    {
        $this->configure(start: 999, end: 1000);
        $allocator = new DocumentNumberAllocatorService();

        $this->assertSame('E310000000999', $this->allocate($allocator));
        $this->assertSame('E310000001000', $this->allocate($allocator));

        try {
            $this->allocate($allocator);
            $this->fail('A used-up range must refuse.');
        } catch (DocumentSequenceUnavailableException $e) {
            $this->assertStringContainsString('used up', $e->getMessage());
        }

        $this->assertSame(1001, $allocator->peek(self::APPS_ID, self::COMPANY_ID, DocumentTypeEnum::ECF_31));
    }

    public function test_an_expired_range_refuses(): void
    {
        $this->configure(start: 4, end: 1000, expiresAt: '2026-06-14');

        $this->expectException(DocumentSequenceUnavailableException::class);
        $this->expectExceptionMessage('expired on 2026-06-14');

        $this->allocate(new DocumentNumberAllocatorService());
    }

    public function test_the_last_valid_day_still_allocates(): void
    {
        $this->configure(start: 4, end: 1000, expiresAt: '2026-06-15');

        $this->assertSame('E310000000004', $this->allocate(new DocumentNumberAllocatorService()));
    }

    public function test_a_new_range_never_moves_the_counter_backwards(): void
    {
        $allocator = new DocumentNumberAllocatorService();
        $this->configure(start: 4, end: 1000);
        $this->allocate($allocator);
        $this->allocate($allocator);

        $sequence = $this->configure(start: 1, end: 2000);

        $this->assertSame(6, $sequence->next_value, 'Numbers 4 and 5 were handed out and are never re-issued.');
        $this->assertSame('E310000000006', $this->allocate($allocator));
    }

    public function test_attention_is_raised_at_eighty_percent_used_and_sixty_days_before_expiry(): void
    {
        $sequence = $this->configure(start: 1, end: 10, expiresAt: '2028-12-31');
        $this->assertSame([], $sequence->attentionReasons());

        $sequence->next_value = 9;
        $this->assertSame(['usage'], $sequence->attentionReasons());

        $sequence->next_value = 2;
        $sequence->expires_at = Carbon::parse('2026-08-10');
        $this->assertSame(['expiry'], $sequence->attentionReasons());
    }

    public function test_plain_sequences_are_unchanged(): void
    {
        $number = new DocumentNumberAllocatorService()->allocate(
            self::APPS_ID,
            self::COMPANY_ID,
            DocumentTypeEnum::INVOICE,
            defaultPrefix: 'INV-',
        );

        $this->assertSame('INV-1', $number);
    }

    public function test_the_configure_command_accepts_full_e_ncfs(): void
    {
        // A fresh Dominican company: the range's authority comes from the company's country when no provider is set.
        $company = Companies::factory()->create(['users_id' => static::$cachedUser->getId(), 'country_code' => DrFiscalRegime::COUNTRY]);

        $this->artisan('kanvas-scribe:ecf-sequence', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
            'type' => 'E31',
            'start' => 'E310000000004',
            'end' => 'E310000001000',
            'expires_at' => '2028-12-31',
        ])->assertSuccessful();
    }

    public function test_the_configure_command_rejects_a_mismatched_prefix(): void
    {
        $company = static::$cachedUser->getCurrentCompany();

        $this->artisan('kanvas-scribe:ecf-sequence', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
            'type' => '31',
            'start' => 'E320000000004',
            'end' => 'E310000001000',
            'expires_at' => '2028-12-31',
        ])->assertFailed();
    }

    private function configure(int $start, int $end, ?string $expiresAt = '2028-12-31'): DocumentSequence
    {
        return new DocumentNumberAllocatorService()->configureRange(
            appsId: self::APPS_ID,
            companiesId: self::COMPANY_ID,
            documentType: DocumentTypeEnum::ECF_31,
            prefix: EcfTypeEnum::CREDITO_FISCAL->series(),
            rangeStart: $start,
            rangeEnd: $end,
            expiresAt: $expiresAt !== null ? Carbon::parse($expiresAt) : null,
            padding: 10,
            country: DrFiscalRegime::COUNTRY,
        );
    }

    private function allocate(DocumentNumberAllocatorService $allocator): string
    {
        return $allocator->allocate(self::APPS_ID, self::COMPANY_ID, DocumentTypeEnum::ECF_31);
    }
}
