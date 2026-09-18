<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Kanvas\Connectors\Reynolds\DataTransferObject\Customer;
use Kanvas\Connectors\Reynolds\DataTransferObject\Lead as LeadData;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LeadDataProviderNameTest extends TestCase
{
    #[DataProvider('internetProspectTypes')]
    public function testProviderNameIsSentForInternetProspects(string $prospectType): void
    {
        $prospect = $this->leadData($prospectType, 'Cars.com')->toProspect();

        $this->assertSame('Cars.com', $prospect['ProviderName']);
    }

    #[DataProvider('nonInternetProspectTypes')]
    public function testProviderNameIsOtherForNonInternetProspects(string $prospectType): void
    {
        $prospect = $this->leadData($prospectType, 'Cars.com')->toProspect();

        $this->assertSame('Other', $prospect['ProviderName']);
        $this->assertSame($prospectType, $prospect['ProspectType']);
    }

    public static function internetProspectTypes(): array
    {
        return [
            'canonical' => ['Internet'],
            'lowercase' => ['internet'],
            'uppercase' => ['INTERNET'],
        ];
    }

    public static function nonInternetProspectTypes(): array
    {
        return [
            'phone' => ['Phone'],
            'other' => ['Other'],
            'list' => ['List'],
        ];
    }

    private function leadData(string $prospectType, string $providerName): LeadData
    {
        return new LeadData(
            prospectId: null,
            prospectCategory: 'Sales',
            prospectType: $prospectType,
            prospectStatus: 'Active',
            providerName: $providerName,
            prospectNote: null,
            isAiGenerated: null,
            primarySalesPerson: null,
            customer: $this->customer(),
        );
    }

    private function customer(): Customer
    {
        return new Customer(
            isBusiness: false,
            nameRecId: null,
            firstName: 'Test',
            lastName: 'Buyer',
            middleName: null,
            businessName: null,
            address: [],
            phones: [],
            email: null,
            consent: [],
        );
    }
}
