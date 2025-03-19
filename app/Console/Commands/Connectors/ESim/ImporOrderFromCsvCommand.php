<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ESim\Actions\ImportOrderFromCsvAction;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
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

class ImporOrderFromCsvCommand extends Command
{
    public Apps $app;
    public Companies $company;
    public Regions $region;
    public Users $user;
    public string $url;
    use KanvasJobsTrait;
    protected $signature = "kanvas:import-order-from-csv {app_id} {company_id} {region_id} {user_id} {url}";


    public function handle(): void
    {
        $this->app = Apps::getById($this->argument("app_id"));
        $this->company = Companies::getById($this->argument("company_id"));
        $this->region = Regions::getById($this->argument("region_id"));
        $this->user = UsersRepository::getUserOfAppById((int)$this->argument("user_id"), $this->app);
        $this->url = $this->argument("url");
        $this->process();
    }

    public function process(): void
    {
        $fileName = Str::uuid() . '.csv';

        $response = Http::get($this->url);

        if ($response->successful()) {
            Storage::put("downloads/{$fileName}", $response->body());
            $path = Storage::path("downloads/{$fileName}");
        } else {
            $this->error("Error al descargar el archivo.");
        }
        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);
        $reader->setEscape('');

        $records = $reader->getRecords();
        $collection = collect($records);
        $app = $this->app;
        $company = $this->company;
        foreach ($collection as $order) {
            $kanvasOrder = Order::getByCustomField('order_reference', $order['order_reference'], $company);
            if ($kanvasOrder) {
                $this->info('Order already exists: ' . $kanvasOrder->order_number);
                continue;
            }
            $items = $collection->where('order_reference', $order['order_reference']);
            $items = $items->filter(function ($item) {
                return Variants::where('sku', $item['sku'])->exists();
            });
            if (! $items->count() > 0) {
                $this->info("Ignoring SKU not found: {$order['sku']}\n");
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
            $people = PeoplesRepository::getByEmail($order['email'], $this->company, $this->app);
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
                    app: $this->app,
                    branch: $this->company->defaultBranch,
                    user: $this->user,
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
                'app' => $this->app,
                'region' => $this->region,
                'company' => $this->company,
                'people' => $people,
                'user' => $this->user,
                'token' => $order['key'],
                'orderNumber' => '',
                'total' => (float) $total,
                'taxes' => 0.0,
                'totalDiscount' => 0.0,
                'totalShipping' => 0.0,
                'status' => OrderStatusEnum::COMPLETED->value,
                'checkoutToken' => '',
                'currency' => Currencies::getByCode('USD'),
                'items' => $items
            ]);
            $action = new CreateOrderAction($dto);
            $action->disableWorkflow();
            $kanvasOrder = $action->execute();
            $kanvasOrder->set('order_reference', $order['order_reference']);
            $this->info("Order created: {$kanvasOrder->order_number}\n");
        }
    }
}
