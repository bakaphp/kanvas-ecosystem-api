<?php

declare(strict_types=1);

namespace Tests\Connectors\PayWay;

use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PayWay\Services\PayWayTokenizationService;
use Kanvas\Payments\Models\PaymentMethods;
use Tests\TestCase;

class PayWayTokenizationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
    }

    private function makeService(?Apps $app = null, ?Companies $company = null): PayWayTokenizationService
    {
        return new PayWayTokenizationService(
            $app ?? $this->currentApp,
            $company ?? $this->currentCompany,
        );
    }

    private function createPaymentMethodRow(string $token, int $companiesId): PaymentMethods
    {
        $pm = new PaymentMethods();
        $pm->apps_id = $this->currentApp->getId();
        $pm->companies_id = $companiesId;
        $pm->users_id = static::$cachedUser->getId();
        $pm->processor = 'payway';
        $pm->is_deleted = 0;
        $pm->metadata = ['payway_numero_uuid' => $token];
        $pm->saveOrFail();

        return $pm;
    }

    public function testTokenizeThrowsDomainException(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PayWay does not support standalone tokenization');

        $this->makeService()->tokenize(['number' => '4111...'], static::$cachedUser);
    }

    public function testTokenizeThrowsRegardlessOfInput(): void
    {
        $this->expectException(DomainException::class);

        $this->makeService()->tokenize([
            'number' => '4111111111111111',
            'cvv' => '123',
            'expiration_date' => '2030-12-31',
            'brand' => 'visa',
            'firstname' => 'Test',
        ], static::$cachedUser);
    }

    public function testDeleteTokenSoftDeletesMatchingRow(): void
    {
        $token = 'payway-uuid-abc-1234';
        $pm = $this->createPaymentMethodRow($token, $this->currentCompany->getId());

        $result = $this->makeService()->deleteToken($token);

        $this->assertTrue($result);
        $this->assertSame(1, (int) $pm->fresh()->is_deleted);
    }

    public function testDeleteTokenReturnsTrueWhenNoRowMatches(): void
    {
        $result = $this->makeService()->deleteToken('nonexistent-uuid-xxxxxxxx');

        $this->assertTrue($result);
    }

    public function testDeleteTokenDoesNotTouchRowsFromOtherCompanies(): void
    {
        $otherCompany = Companies::factory()->create();
        $token = 'shared-token-cross-tenant';

        $myRow = $this->createPaymentMethodRow($token, $this->currentCompany->getId());
        $otherRow = $this->createPaymentMethodRow($token, $otherCompany->getId());

        $this->makeService($this->currentApp, $this->currentCompany)->deleteToken($token);

        $this->assertSame(1, (int) $myRow->fresh()->is_deleted);
        $this->assertSame(0, (int) $otherRow->fresh()->is_deleted);
    }
}
