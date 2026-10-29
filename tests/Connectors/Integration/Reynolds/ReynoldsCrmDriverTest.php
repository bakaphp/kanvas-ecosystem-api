<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmCatalogRef;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmConsent;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmFollowUp;
use Kanvas\Connectors\Reynolds\DataTransferObject\Crm\CrmLeadInfo;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CrmTypeEnum;
use Kanvas\Connectors\Reynolds\Services\ReynoldsCrmDriver;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class ReynoldsCrmDriverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testTheTypeBuildsItsDriverAndTheDriverReportsItsType(): void
    {
        $driver = CrmTypeEnum::REYNOLDS->driver();

        $this->assertInstanceOf(ReynoldsCrmDriver::class, $driver);
        $this->assertSame(CrmTypeEnum::REYNOLDS, $driver->type());
        $this->assertNotSame($driver, CrmTypeEnum::REYNOLDS->driver(), 'a driver is never shared between calls');
        $this->assertNull(CrmTypeEnum::tryFrom('not-a-crm'));
    }

    public function testItNamesTheFieldsTheWebhookAlreadyWrites(): void
    {
        $driver = new ReynoldsCrmDriver();

        $this->assertSame('REYNOLDS_CLIENT_ID', $driver->clientIdField());
        $this->assertSame('REYNOLDS_PROSPECT_ID', $driver->leadIdField());
    }

    public function testACompanyIsConfiguredWhenItHasADealerNumber(): void
    {
        $driver = new ReynoldsCrmDriver();

        $this->assertTrue($driver->isConfigured($this->company('FIXTUREDEALER001')));
        $this->assertFalse($driver->isConfigured($this->company(null)));
    }

    public function testOpenIsTheCompanysActiveStatus(): void
    {
        $driver = new ReynoldsCrmDriver();

        $this->assertSame('Active', $driver->resolveStatusName('Open'));
        $this->assertSame('Sold', $driver->resolveStatusName('Sold'));
        $this->assertSame('Closed', $driver->resolveStatusName('Closed'));
        $this->assertNull($driver->resolveStatusName(null));
    }

    public function testTheOwnerIsResolvedByNameWithinTheCompany(): void
    {
        $driver = new ReynoldsCrmDriver();
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->assertNotNull($driver->resolveOwnerId($user->lastname . ', ' . $user->firstname, $company));
        $this->assertNull($driver->resolveOwnerId('Nobody, Atall ' . uniqid(), $company));
        $this->assertNull($driver->resolveOwnerId(null, $company));
    }

    public function testOnlyTheLanguageBecomesACustomerField(): void
    {
        $driver = new ReynoldsCrmDriver();

        $this->assertSame(
            ['REYNOLDS_LANGUAGE' => 'es'],
            $driver->customerCustomFields(new CrmCustomer(language: 'es'))
        );
        $this->assertSame([], $driver->customerCustomFields(new CrmCustomer()));
    }

    public function testConsentBecomesTheFieldsTheWebhookWrites(): void
    {
        $driver = new ReynoldsCrmDriver();
        $customer = new CrmCustomer(consent: new CrmConsent(
            follow_up: new CrmFollowUp(
                phone: 'Y',
                mail: 'N',
                email: 'TRUE',
                text: null
            )
        ));

        $this->assertSame(
            [
                'REYNOLDS_CONSENT_PHONE' => 'Y',
                'REYNOLDS_CONSENT_MAIL' => 'N',
                'REYNOLDS_CONSENT_EMAIL' => 'Y',
            ],
            $driver->customerCustomFields($customer)
        );
    }

    public function testTypeAndStatusBecomeLeadFieldsButTheSourceDoesNot(): void
    {
        $driver = new ReynoldsCrmDriver();
        $lead = new CrmLeadInfo(
            type: new CrmCatalogRef('6', 'Walk-In'),
            source: new CrmCatalogRef('102623', '*Drove By'),
            status: 'Open'
        );

        $this->assertSame(
            ['REYNOLDS_PROSPECT_TYPE' => 'Walk-In', 'REYNOLDS_PROSPECT_STATUS_TYPE' => 'Open'],
            $driver->leadCustomFields($lead)
        );
        $this->assertSame([], $driver->leadCustomFields(new CrmLeadInfo()));
    }

    private function company(?string $dealerNumber): CompanyInterface
    {
        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('get')
            ->with(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value)
            ->andReturn($dealerNumber);

        return $company;
    }
}
