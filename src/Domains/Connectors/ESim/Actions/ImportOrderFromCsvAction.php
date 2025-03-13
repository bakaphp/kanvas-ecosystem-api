<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use League\Csv\Reader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kanvas\Souk\Orders\DataTransferObject\Order as OrderDTO;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Spatie\LaravelData\DataCollection;
use Kanvas\Users\Models\Users;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;

class ImportOrderFromCsvAction
{
    public function __construct(
        public Apps $apps,
        public Companies $companies,
        public Regions $regions,
        public Users $users,
        public string $url
    ) {
    }

    public function execute(): void
    {
        $fileName = Str::uuid() . '.csv';

        // $response = Http::get($this->url);

        // if ($response->successful()) {
        //     Storage::put("downloads/{$fileName}", $response->body());
        //     $path = Storage::path("downloads/{$fileName}");
        // } else {
        //     echo "Error al descargar el archivo.";
        // }
        $path = "/var/www/html/storage/app/downloads/c5db9f29-03e6-4fc8-b334-9755acbe4922.csv";
        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);
        $reader->setEscape('');

        $records = $reader->getRecords();
        $collection = collect($records);
        $app = $this->apps;
        $company = $this->companies;
        foreach ($collection as $order) {
            $kanvasOrder = Order::where('order_number', $order['order_reference'])
                ->first();
            if ($kanvasOrder) {
                continue;
            }
            $items = $collection->where('order_reference', $order['order_reference']);
            $items = $items->filter(function ($item) {
                return Variants::where('sku', $item['sku'])->exists();
            });
            if (!$items->count() > 0) {
                echo "Ignoring SKU not found: {$order['sku']}\n";
                continue;
            }
            $items = $items->map(function ($item) use ($order, $app, $collection, $company) {
                if ($item['order_reference'] == $order['order_reference']) {
                    $variant = Variants::getBySku($item['sku'], $company, $app);
                    if ($variant) {
                        $orderItem = new OrderItem(
                            app: $app,
                            variant: $variant,
                            name: $item['name'],
                            sku: $variant->sku,
                            quantity: 1,
                            price: (float) $item['price'],
                            tax: 0,
                            discount: 0.0,
                            currency: Currencies::getByCode('USD'),
                            quantityShipped: 0
                        );
                        return $orderItem;
                    }
                }
            });
            $people = PeoplesRepository::getByEmail($order['email'], $this->companies, $this->apps);
            if (! $people) {
                $contact = [
                    [
                        'value' => $order['email'],
                        'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                        'weight' => 0
                    ]
                ];
                $name = explode(' ', $order['name']);
                $peopleDto = new PeopleDTO(
                    app: $this->apps,
                    branch: $this->companies->defaultBranch,
                    user: $this->users,
                    firstname: $name[0],
                    lastname: isset($name[1]) ? $name[1] : '',
                    contacts: Contact::collect($contact, DataCollection::class),
                    address: Address::collect([], DataCollection::class)
                );
                $people = (new CreatePeopleAction(
                    $peopleDto
                ))->execute();
            }
            $total = $items->sum('price');
            $items = OrderItem::collect($items, DataCollection::class);
            $dto = OrderDTO::from([
                'app' => $this->apps,
                'region' => $this->regions,
                'company' => $this->companies,
                'people' => $people,
                'user' => $this->users,
                'token' => $order['key'],
                'orderNumber' => '',
                'total' => (float)$total,
                'taxes' => 0.0,
                'totalDiscount' => 0.0,
                'totalShipping' => 0.0,
                'status' => OrderStatusEnum::COMPLETED->value,
                'checkoutToken' => '',
                'currency' => Currencies::getByCode('USD'),
                'items' => $items
            ]);
            $order = (
                new CreateOrderAction(
                    $dto
                )
            )->execute();
            echo "Order created: {$order->order_number}\n";
        }
    }
}
