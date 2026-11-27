<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Tests\TestCase;

final class RegionOrderTypeGatewaysTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'commerce'];

    public function testTheStorefrontReadsTheGatewayOfEachOrderTypeFromTheRegion(): void
    {
        $region = $this->region();
        $settings = [
            'language' => 'es-DO',
            'timezone' => 'America/Santo_Domingo',
            'default_payment_gateway' => 'portal',
            'payment_gateways' => ['portal', 'azul'],
            'order_type_payment_gateways' => ['impound_lot' => 'azul', 'paso_rapido' => 'azul'],
        ];

        $this->gql('
            mutation {
                updateRegion(id: ' . $region->getId() . ', input: {
                    settings: ' . json_encode(json_encode($settings)) . '
                }) {
                    id
                    short_slug
                }
            }
        ')->assertJsonPath('data.updateRegion.short_slug', $region->short_slug);

        $read = $this->gql('
            query {
                regions(where: { column: ID, operator: EQ, value: ' . $region->getId() . ' }) {
                    data {
                        id
                        short_slug
                        settings
                        settingsData {
                            language
                            timezone
                            default_payment_gateway
                            payment_gateways
                            order_type_payment_gateways
                        }
                    }
                }
            }
        ');
        $read->assertJsonPath('data.regions.data.0.settingsData.order_type_payment_gateways', $settings['order_type_payment_gateways']);
        $read->assertJsonPath('data.regions.data.0.settingsData.timezone', 'America/Santo_Domingo');
        $this->assertSame($settings, json_decode($read->json('data.regions.data.0.settings'), true));

        $this->assertSame('azul', $this->storefrontGateway($this->order($region, 'impound_lot')));
        $this->assertSame('portal', $this->storefrontGateway($this->order($region, 'movipass')));
    }

    public function testCardsCanBeListedForOneProcessor(): void
    {
        $azul = $this->paymentMethod('azul');
        $this->paymentMethod('portal');

        $cards = $this->gql('
            query {
                paymentMethods(where: { column: PROCESSOR, operator: EQ, value: "azul" }) {
                    data {
                        id
                        processor
                        payment_methods_brand
                        payment_ending_numbers
                    }
                }
            }
        ')->json('data.paymentMethods.data');

        $this->assertNotEmpty($cards);
        $this->assertSame(['azul'], array_values(array_unique(array_column($cards, 'processor'))));
        $this->assertContains((string) $azul->getId(), array_column($cards, 'id'));
    }

    private function storefrontGateway(Order $order): ?string
    {
        $data = $this->gql('
            query {
                orders(where: { column: ID, operator: EQ, value: ' . $order->getId() . ' }) {
                    data {
                        id
                        order_type {
                            name
                        }
                        region {
                            short_slug
                            settingsData {
                                default_payment_gateway
                                order_type_payment_gateways
                            }
                        }
                    }
                }
            }
        ')->json('data.orders.data.0');

        $settings = $data['region']['settingsData'];

        return $settings['order_type_payment_gateways'][$data['order_type']['name']] ?? $settings['default_payment_gateway'];
    }

    private function region(): Regions
    {
        $region = new Regions();
        $region->apps_id = app(Apps::class)->getId();
        $region->companies_id = auth()->user()->getCurrentCompany()->getId();
        $region->users_id = auth()->user()->getId();
        $region->name = 'Gateway region';
        $region->slug = 'gateway-region-' . uniqid();
        $region->short_slug = 'GW' . uniqid();
        $region->currency_id = Currencies::getByCode('USD')->getId();
        $region->is_default = 0;
        $region->settings = ['language' => 'es-DO', 'default_payment_gateway' => 'portal'];
        $region->saveOrFail();

        return $region;
    }

    private function order(Regions $region, string $orderTypeName): Order
    {
        $app = app(Apps::class);
        $orderType = OrderTypes::withoutSyncingToSearch(fn () => OrderTypes::firstOrCreate(
            ['apps_id' => $app->getId(), 'name' => $orderTypeName],
            ['companies_id' => 0]
        ));

        return Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->withUserId(auth()->user()->getId())
            ->create(['region_id' => $region->getId(), 'order_types_id' => $orderType->getId()]);
    }

    private function paymentMethod(string $processor): PaymentMethods
    {
        return PaymentMethods::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'users_id' => auth()->user()->getId(),
            'payment_ending_numbers' => '1111',
            'payment_methods_brand' => 'visa',
            'expiration_date' => '2030-12',
            'processor' => $processor,
        ]);
    }

    private function gql(string $query): TestResponse
    {
        $company = auth()->user()->getCurrentCompany();

        return $this->graphQL($query, [], [], [
            'X-Kanvas-App' => app(Apps::class)->key,
            'X-Kanvas-Location' => $company->branch->uuid,
        ])->assertSuccessful()->assertJsonMissingPath('errors');
    }
}
