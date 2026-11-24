<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Support\Facades\Auth;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateCustomerQuoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetVariantStockTool;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Support\DocumentActionsSetup;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Users\Models\Users;
use Tests\Scribe\Delivery\DocumentDeliveryTestCase;

/**
 * The quote a customer-facing agent may make: for whoever the conversation is with, at the catalog's
 * price, handed over as the tracked page link.
 */
final class CreateCustomerQuoteToolTest extends DocumentDeliveryTestCase
{
    private People $prospect;

    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        if (Warehouses::getDefault($this->company, $this->kanvasApp) === null) {
            new InventorySetup($this->kanvasApp, Auth::user(), $this->company)->run();
        }

        $this->prospect = People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['firstname' => 'Max', 'lastname' => 'Castro']);
    }

    public function testAGrantedToolQuotesTheConversationsPersonAtTheCatalogPrice(): void
    {
        $variant = $this->pricedVariant(25.0);
        $lead = Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $this->company->getId())
            ->withPeopleId($this->prospect->getId())
            ->create();

        $result = $this->grantedTo($lead)->__invoke(lines: [['variant_id' => $variant->getId(), 'quantity' => 3]]);

        $this->assertTrue($result['created'], json_encode($result));
        $this->assertSame(75.0, $result['total']);
        $this->assertSame(25.0, $result['lines'][0]['unit_price']);
        $this->assertStringStartsWith('https://', $result['link']);

        $quote = $this->quoted($result);
        $this->assertSame(QuoteStatusEnum::SENT, $quote->status);
        $this->assertSame($this->prospect->getId(), (int) $quote->contact_people_id);
        $this->assertSame('Max Castro', $quote->customer->name);
        $this->assertTrue($this->prospect->organizations()->whereKey($quote->customer_organization_id)->exists());

        $this->assertTrue(
            Engagement::query()
                ->where('people_id', $this->prospect->getId())
                ->where('slug', DocumentActionEnum::VIEW_QUOTE->value)
                ->exists(),
            'The link is the tracked quote page, opened for the person quoted.'
        );
    }

    public function testThePersonsOwnOrganizationIsBilledWhenTheyHaveOne(): void
    {
        $this->customer->addPeople($this->prospect);

        $result = $this->quote($this->pricedVariant(10.0));

        $quote = $this->quoted($result);
        $this->assertSame($this->customer->getId(), (int) $quote->customer_organization_id);
    }

    public function testANamesakeOrganizationIsNeverTakenOver(): void
    {
        $namesake = $this->seedTestOrganization('Max Castro');

        $result = $this->quote($this->pricedVariant(10.0));

        $quote = $this->quoted($result);
        $this->assertNotSame($namesake->getId(), (int) $quote->customer_organization_id);
        $this->assertFalse($this->prospect->organizations()->whereKey($namesake->getId())->exists());
    }

    public function testAnotherCompanysProductIsRefusedAndNothingIsQuoted(): void
    {
        $result = $this->quote($this->variantOf(Auth::user()->getCurrentCompany()));

        $this->assertFalse($result['created']);
        $this->assertSame('variant_not_found', $result['reason']);
        $this->assertSame(0, $this->quotesOfProspect());
    }

    public function testAProductWithNoCatalogPriceIsRefused(): void
    {
        $unpriced = $this->variantOf($this->company);
        $unpriced->channels()->detach();

        $result = $this->quote($unpriced);

        $this->assertFalse($result['created']);
        $this->assertSame('variant_not_priced', $result['reason']);
    }

    public function testAConversationWithNoPersonOnFileGetsNoQuote(): void
    {
        $result = new CreateCustomerQuoteTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(lines: [['variant_id' => $this->pricedVariant(10.0)->getId()]]);

        $this->assertFalse($result['created']);
        $this->assertSame('no_customer_in_conversation', $result['reason']);
    }

    public function testACompanyWithNoQuotePageLeavesNothingSent(): void
    {
        $this->company->del(DocumentActionsSetup::PAGE_CONFIG_KEY);

        $result = $this->quote($this->pricedVariant(10.0));

        $this->assertFalse($result['created']);
        $this->assertSame(0, Quote::query()
            ->where('contact_people_id', $this->prospect->getId())
            ->where('status', QuoteStatusEnum::SENT->value)
            ->count());
    }

    private function toolFor(People $person): CreateCustomerQuoteTool
    {
        return new CreateCustomerQuoteTool(person: $person)
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    /**
     * Built the way an agent builds a tool it was granted from the catalog, which is the only way this
     * one is ever handed out.
     */
    private function grantedTo(Lead $lead): CreateCustomerQuoteTool
    {
        $row = new Tool();
        $row->handler = CreateCustomerQuoteTool::class;

        $tool = new CustomerQuoteToolHostStub([
            $this->kanvasApp,
            $this->company,
            static::$cachedUser,
            $lead,
        ])->resolve($row);

        $this->assertInstanceOf(CreateCustomerQuoteTool::class, $tool);

        return $tool;
    }

    /**
     * @return array<string, mixed>
     */
    private function quote(Variants $variant): array
    {
        return $this->toolFor($this->prospect)->__invoke(lines: [['variant_id' => $variant->getId()]]);
    }

    private function pricedVariant(float $price): Variants
    {
        $variant = $this->variantOf($this->company);

        Variants::withoutSyncingToSearch(
            fn () => new SetVariantStockTool()
                ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
                ->__invoke(variant_id: $variant->getId(), quantity: 5, price: $price)
        );

        return $variant;
    }

    private function variantOf(Companies $company): Variants
    {
        return Products::withoutSyncingToSearch(
            fn () => Variants::withoutSyncingToSearch(
                fn () => Products::factory()->company($company->getId())->create()->variants()->first()
            )
        );
    }

    /**
     * Quote numbers restart per company, so the number alone finds another company's quote.
     *
     * @param array<string, mixed> $result
     */
    private function quoted(array $result): Quote
    {
        $this->assertTrue($result['created'], json_encode($result));

        return Quote::query()
            ->where('companies_id', $this->company->getId())
            ->where('quote_number', $result['quote_number'])
            ->firstOrFail();
    }

    private function quotesOfProspect(): int
    {
        return Quote::query()->where('contact_people_id', $this->prospect->getId())->count();
    }
}

final class CustomerQuoteToolHostStub implements ProvidesToolDependencies
{
    use MergesRegisteredTools;

    /**
     * @param list<Apps|Companies|Users|Lead> $candidates
     */
    public function __construct(
        private readonly array $candidates
    ) {
    }

    public function toolDependencyCandidates(): array
    {
        return $this->candidates;
    }

    public function resolve(Tool $tool): ?object
    {
        return $this->defaultRegisteredToolResolver($tool);
    }
}
