<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Exporters;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Exporters\AffiliateCommissionsRecordExporter;
use Kanvas\Intelligence\Agents\Neuron\Exporters\RecordExporterRegistry;
use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

final class AffiliateCommissionsRecordExporterTest extends TestCase
{
    protected Apps $apps;
    protected $user;
    protected $company;

    public function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
    }

    public function testRegisteredInRegistry(): void
    {
        $this->assertContains('affiliate_commissions', new RecordExporterRegistry()->types());
    }

    public function testExportsCompanyConversionsAndFiltersByAffiliate(): void
    {
        $programId = $this->createProgram();
        $affiliateUa20 = $this->createAffiliate($programId, 'UA20 Distributor');
        $affiliateUa21 = $this->createAffiliate($programId, 'UA21 Distributor');

        $this->createConversion($affiliateUa20);
        $this->createConversion($affiliateUa20);
        $this->createConversion($affiliateUa21);

        $exporter = new AffiliateCommissionsRecordExporter();

        $all = $exporter->rows($this->apps, $this->company, []);
        $this->assertCount(3, $all);
        $this->assertCount(count($exporter->headers()), $all[0]);

        $ua20 = $exporter->rows($this->apps, $this->company, ['affiliate' => 'UA20']);
        $this->assertCount(2, $ua20);

        // Case-insensitive match against the affiliate.
        $ua20Lower = $exporter->rows($this->apps, $this->company, ['affiliate' => 'ua20']);
        $this->assertCount(2, $ua20Lower);
    }

    public function testUnknownAffiliateThrows(): void
    {
        $exporter = new AffiliateCommissionsRecordExporter();

        $this->expectException(ValidationException::class);
        $exporter->rows($this->apps, $this->company, ['affiliate' => 'DOES-NOT-EXIST']);
    }

    private function createProgram(): string
    {
        return $this->graphQL('
            mutation($input: AffiliateProgramInput!) {
                createAffiliateProgram(input: $input) { id }
            }
        ', ['input' => ['name' => 'Program ' . uniqid(), 'default_commission_rate' => 10.00]])
            ->assertSuccessful()
            ->json('data.createAffiliateProgram.id');
    }

    private function createAffiliate(string $programId, string $name): string
    {
        return $this->graphQL('
            mutation($input: AffiliateInput!) {
                createAffiliate(input: $input) { id }
            }
        ', ['input' => [
            'affiliate_program_id' => $programId,
            'name' => $name,
            'email' => fake()->unique()->email(),
            'commission_rate' => 10.00,
        ]])
            ->assertSuccessful()
            ->json('data.createAffiliate.id');
    }

    private function createConversion(string $affiliateId): void
    {
        $order = $this->createOrder();

        $this->graphQL('
            mutation($input: AffiliateConversionInput!) {
                createAffiliateConversion(input: $input) { id }
            }
        ', ['input' => [
            'affiliate_id' => $affiliateId,
            'order_id' => $order->getId(),
            'order_total' => 100.00,
            'eligible_amount' => 100.00,
            'commission_type' => 'PERCENTAGE',
            'commission_rate' => 10.00,
            'commission_amount' => 10.00,
        ]])->assertSuccessful();
    }

    private function createOrder(): Order
    {
        $people = People::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create();

        return Order::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create(['people_id' => $people->getId()]);
    }
}
