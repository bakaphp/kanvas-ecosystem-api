<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Shipping;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\PendingCommand;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Shipping\RateCards\Models\RateCard;
use Kanvas\Souk\Shipping\RateCards\Models\RateCardCountry;
use Kanvas\Souk\Shipping\RateCards\Models\RateCardRate;
use Kanvas\Users\Models\UserCompanyApps;
use Tests\TestCase;

final class ImportShippingRateCardsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const REAL_FILE = 'database/seeders/data/shipping/inposdom/shipping_rate_cards.json';

    protected $connectionsToTransact = [null, 'ecosystem', 'commerce'];

    public function testItImportsTheRealFileAndPrintsCounts(): void
    {
        $company = Companies::factory()->create();

        $this->runImport($company, self::REAL_FILE)
            ->expectsOutputToContain('cards_upserted: 2')
            ->expectsOutputToContain('countries_upserted: 44')
            ->expectsOutputToContain('cards_deleted: 0')
            ->assertSuccessful();

        $this->assertSame(2, RateCard::query()->fromCompany($company)->count());
        $this->assertSame(44, RateCardCountry::query()->fromCompany($company)->count());
        $this->assertGreaterThan(0, $this->rateCount($company));
    }

    public function testASecondRunCreatesNoDuplicates(): void
    {
        $company = Companies::factory()->create();
        $this->runImport($company, self::REAL_FILE)->assertSuccessful();
        $cardsBefore = $this->rawCardCount($company);
        $ratesBefore = $this->rateCount($company);

        $this->runImport($company, self::REAL_FILE)->expectsOutputToContain('cards_deleted: 0')->assertSuccessful();

        $this->assertSame($cardsBefore, $this->rawCardCount($company));
        $this->assertSame($ratesBefore, $this->rateCount($company));
    }

    public function testItFailsForAMissingFile(): void
    {
        $this->runImport(Companies::factory()->create(), 'database/does-not-exist.json')->assertFailed();
    }

    public function testItFailsWhenTheCompanyIsNotAssociatedWithTheApp(): void
    {
        $company = Companies::factory()->create();
        UserCompanyApps::query()->where('companies_id', $company->getId())->delete();

        $this->runImport($company, self::REAL_FILE)->expectsOutputToContain('not associated')->assertFailed();

        $this->assertSame(0, $this->rawCardCount($company));
    }

    public function testItPrintsValidationMessagesAndFailsForAMalformedFile(): void
    {
        $company = Companies::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'rate_cards');
        file_put_contents($path, json_encode(['inposdom' => 'oops']));

        $this->runImport($company, $path)->expectsOutputToContain("Provider 'inposdom' must be an object.")->assertFailed();

        unlink($path);
    }

    private function runImport(Companies $company, string $file): PendingCommand
    {
        return $this->artisan('kanvas:shipping-import-rate-cards', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
            'file' => $file,
        ]);
    }

    private function rawCardCount(Companies $company): int
    {
        return RateCard::query()->withoutGlobalScopes()->where('companies_id', $company->getId())->count();
    }

    private function rateCount(Companies $company): int
    {
        return RateCardRate::query()
            ->withoutGlobalScopes()
            ->whereIn('rate_card_id', RateCard::query()->withoutGlobalScopes()->where('companies_id', $company->getId())->pluck('id'))
            ->count();
    }
}
