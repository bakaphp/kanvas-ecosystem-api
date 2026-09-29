<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists\PriceDisclosure;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Neuron\Tools\VehiclePriceDisclosureTool;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Actions\RenderPriceDisclosureAction;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureChannelEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadHandOffNotification;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class VehiclePriceDisclosureToolTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'inventory', 'crm', 'social'];

    private const string SMS_TEMPLATE = 'Hi {{ $first_name }}, Sally here with {{ $dealership }}. '
        . 'Vehicle total price: ${{ $ca_cars_total_price }}. '
        . 'Actual price before government-required taxes and fees: ${{ $ftc_actual_price }}. '
        . 'This actual price includes ${{ $mandatory_dealer_fees }} in dealer-required fees. Stock {{ $stock_number }}.';

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        new InventorySetup($this->kanvasApp, $this->user, $this->company)->run();
        $this->company->set(PriceDisclosureConfigurationEnum::ENABLED->value, 1);
    }

    /**
     * The regime flag is a company setting written to Redis, which no transaction rolls back, and
     * the test company is shared by every test in the run.
     */
    protected function tearDown(): void
    {
        $this->company->del(PriceDisclosureConfigurationEnum::ENABLED->value);

        parent::tearDown();
    }

    public function testRendersTheApprovedTemplateWithTheChannelPriceVerbatim(): void
    {
        $variant = $this->makePricedVariant(25000.0);
        $lead = $this->makeLead($variant->sku);
        $this->makeTemplate(PriceDisclosureChannelEnum::SMS, 'en');

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'sms');

        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['outcome']);
        $this->assertFalse($result['suppress']);
        $this->assertSame('full_message', $result['mode']);
        $this->assertStringContainsString('Vehicle total price: $25,000.00.', $result['message']);
        $this->assertStringContainsString('taxes and fees: $25,000.00.', $result['message']);
        $this->assertStringContainsString('includes $0.00 in dealer-required fees', $result['message']);
        $this->assertStringContainsString('Stock ' . $variant->sku, $result['message']);
        $this->assertStringContainsString("O'Brien", $result['message']);
        $this->assertSame($variant->sku, $result['vehicle_key']);
        $this->assertSame(hash('sha256', $result['message']), $result['content_hash']);
        $this->assertSame(25000.0, $result['price']['ca_cars_total_price']);
        $this->assertSame(25000.0, $result['price']['ftc_actual_price']);
    }

    public function testAnExplicitVinOverridesTheLeadVehicleOfInterest(): void
    {
        $interestVariant = $this->makePricedVariant(25000.0);
        $askedVariant = $this->makePricedVariant(31500.0);
        $lead = $this->makeLead($interestVariant->sku);
        $this->makeTemplate(PriceDisclosureChannelEnum::SMS, 'en');

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'sms', vin: $askedVariant->sku);

        $this->assertTrue($result['success']);
        $this->assertSame($askedVariant->sku, $result['vehicle_key']);
        $this->assertStringContainsString('$31,500.00', $result['message']);
    }

    public function testSelectsTheTemplateForTheChannelAndLanguage(): void
    {
        $variant = $this->makePricedVariant(25000.0);
        $lead = $this->makeLead($variant->sku);
        $this->makeTemplate(PriceDisclosureChannelEnum::EMAIL, 'es', 'Precio total del vehículo: ${{ $ca_cars_total_price }}.');

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'email', language: 'ES');

        $this->assertTrue($result['success']);
        $this->assertSame(RenderPriceDisclosureAction::templateName(PriceDisclosureChannelEnum::EMAIL, 'es'), $result['template']);
        $this->assertSame('Precio total del vehículo: $25,000.00.', $result['message']);
    }

    public function testSuppressesAndHandsOffWhenTheDealerHasNoApprovedTemplate(): void
    {
        $variant = $this->makePricedVariant(25000.0);
        $lead = $this->makeLead($variant->sku);

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'sms');

        $this->assertSuppressedWithHandoff($result, 'template_missing', $lead);
        $this->assertStringNotContainsString('25,000', $result['error']);
    }

    public function testSuppressesAndHandsOffWhenTheVehicleHasNoPrice(): void
    {
        $variant = $this->makePricedVariant(0.0);
        $lead = $this->makeLead($variant->sku);
        $this->makeTemplate(PriceDisclosureChannelEnum::SMS, 'en');

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'sms');

        $this->assertSuppressedWithHandoff($result, 'price_missing', $lead);
    }

    public function testSuppressesAndHandsOffWhenTheVehicleCannotBeResolved(): void
    {
        $lead = $this->makeLead('NO-SUCH-VIN-' . uniqid());
        $this->makeTemplate(PriceDisclosureChannelEnum::SMS, 'en');

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'sms');

        $this->assertSuppressedWithHandoff($result, 'vehicle_unresolved', $lead);
    }

    public function testRejectsAnUnknownChannelWithoutHandingOff(): void
    {
        $variant = $this->makePricedVariant(25000.0);
        $lead = $this->makeLead($variant->sku);

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'whatsapp');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
        $this->assertSame(0, LeadHandOffNotification::query()->where('leads_id', $lead->getId())->count());
    }

    public function testDoesNotResolveAnotherCompanysLead(): void
    {
        $foreignLead = Lead::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId(Companies::factory()->create()->getId())
            ->create();

        $result = $this->tool()->__invoke(lead_id: $foreignLead->getId(), channel: 'sms');

        $this->assertSame('error', $result['status']);
        $this->assertArrayNotHasKey('price', $result);
    }

    public function testAMonthlyPaymentQuestionIsSuppressedBeforeAnyPriceIsRendered(): void
    {
        $variant = $this->makePricedVariant(25000.0);
        $lead = $this->makeLead($variant->sku);
        $this->makeTemplate(PriceDisclosureChannelEnum::SMS, 'en');
        $this->makeInboundMessage($lead, '¿En cuánto me queda al mes?');

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'sms');

        $this->assertSuppressedWithHandoff($result, 'payment_unsupported', $lead);
    }

    public function testAnAddOnQuestionIsSuppressed(): void
    {
        $variant = $this->makePricedVariant(25000.0);
        $lead = $this->makeLead($variant->sku);
        $this->makeTemplate(PriceDisclosureChannelEnum::SMS, 'en');
        $this->makeInboundMessage($lead, 'Is the protection package required?');

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'sms');

        $this->assertSuppressedWithHandoff($result, 'add_on_disclosure_missing', $lead);
    }

    public function testAPriceQuestionStillRendersTheDisclosure(): void
    {
        $variant = $this->makePricedVariant(25000.0);
        $lead = $this->makeLead($variant->sku);
        $this->makeTemplate(PriceDisclosureChannelEnum::SMS, 'en');
        $this->makeInboundMessage($lead, 'How much is the Sierra?');

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'sms');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('$25,000.00', $result['message']);
    }

    public function testTheToolIsANoOpForADealerWithoutTheFlag(): void
    {
        $this->company->del(PriceDisclosureConfigurationEnum::ENABLED->value);
        $variant = $this->makePricedVariant(25000.0);
        $lead = $this->makeLead($variant->sku);
        $this->makeInboundMessage($lead, '¿En cuánto me queda al mes?');

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'sms');

        $this->assertSame('noop', $result['outcome']);
        $this->assertFalse($result['enabled']);
        $this->assertArrayNotHasKey('message', $result);
        $this->assertSame(0, LeadHandOffNotification::query()->where('leads_id', $lead->getId())->count());
    }

    public function testTheAgentsOwnLastMessageIsNotReadAsCustomerIntent(): void
    {
        $variant = $this->makePricedVariant(25000.0);
        $lead = $this->makeLead($variant->sku);
        $this->makeTemplate(PriceDisclosureChannelEnum::SMS, 'en');
        $this->makeInboundMessage($lead, 'Would you like to talk about monthly payments?', fromAgent: true);

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), channel: 'sms');

        $this->assertTrue($result['success']);
    }

    private function assertSuppressedWithHandoff(array $result, string $reason, Lead $lead): void
    {
        $this->assertFalse($result['success']);
        $this->assertSame('denied', $result['outcome']);
        $this->assertTrue($result['suppress']);
        $this->assertArrayNotHasKey('message', $result);
        $this->assertSame($reason, $result['reason_code']);
        $this->assertArrayNotHasKey('price', $result);
        $this->assertTrue($result['handoff']['success']);
        $this->assertSame(1, LeadHandOffNotification::query()->where('leads_id', $lead->getId())->count());
    }

    private function tool(): VehiclePriceDisclosureTool
    {
        return new VehiclePriceDisclosureTool()->withContext($this->kanvasApp, $this->company, $this->user);
    }

    private function makeLead(string $vin): Lead
    {
        /** @var Lead $lead */
        $lead = Lead::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();

        $lead->people->firstname = "O'Brien";
        $lead->people->saveOrFail();

        $lead->set(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value, [
            'isNew' => true,
            'yearFrom' => 2025,
            'make' => 'GMC',
            'model' => 'Sierra',
            'vin' => $vin,
            'isPrimary' => true,
        ]);

        return $lead->refresh();
    }

    private function makeInboundMessage(Lead $lead, string $text, bool $fromAgent = false): void
    {
        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $this->kanvasApp->getId(), 'languages_id' => 1, 'verb' => 'twilio-sms'],
            ['name' => 'SMS']
        );

        $message = Message::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => $text, 'from_me' => $fromAgent, 'from_ia' => $fromAgent],
                'is_locked' => 0,
                'is_un_response' => 0,
            ]);

        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $message->getId(),
            'message_types_id' => $messageType->getId(),
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makePricedVariant(float $price): Variants
    {
        /** @var Products $product */
        $product = Products::withoutSyncingToSearch(fn () => Products::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]));

        /** @var Variants $variant */
        $variant = Variants::withoutSyncingToSearch(fn () => Variants::factory()
            ->withProductId($product->getId())
            ->create(['sku' => 'VIN' . strtoupper(uniqid())]));

        $warehouse = Warehouses::fromApp($this->kanvasApp)->fromCompany($this->company)->firstOrFail();
        $channel = Channels::getDefault($this->company, $this->kanvasApp);

        $variantWarehouse = VariantsWarehouses::updateOrCreate(
            [
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
            ],
            [
                'quantity' => 1,
                'price' => $price,
                'sku' => $variant->sku,
                'position' => 1,
                'is_default' => 1,
            ],
        );

        VariantsChannels::updateOrCreate(
            [
                'product_variants_warehouse_id' => $variantWarehouse->getId(),
                'channels_id' => $channel->getId(),
            ],
            [
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
                'price' => $price,
                'discounted_price' => 0,
                'is_published' => 1,
            ],
        );

        return $variant;
    }

    private function makeTemplate(PriceDisclosureChannelEnum $channel, string $language, string $template = self::SMS_TEMPLATE): void
    {
        new CreateTemplateAction(
            TemplateInput::from([
                'app' => $this->kanvasApp,
                'company' => $this->company,
                'name' => RenderPriceDisclosureAction::templateName($channel, $language),
                'template' => $template,
            ])
        )->execute();
    }
}
