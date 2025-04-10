<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order as OrderDTO;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use League\Csv\Reader;
use Spatie\LaravelData\DataCollection;
use Kanvas\Connectors\ESim\DataTransferObject\ESim;
use Kanvas\Connectors\CMLink\Actions\CreateEsimOrderAction;
use Kanvas\Connectors\ESim\DataTransferObject\ESimStatus;
use Kanvas\Connectors\ESim\Support\FileSizeConverter;
use Kanvas\Connectors\CMLink\Enums\PlanTypeEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;

class ImporOrderFromCsvCommand extends Command
{
    use KanvasJobsTrait;
    public Apps $app;
    public Companies $company;
    public Regions $region;
    public Users $user;
    public string $url;
    protected $signature = 'kanvas:import-order-from-csv {app_id} {company_id} {region_id} {user_id} {url}';

    public function handle(): void
    {
        $this->app = Apps::getById($this->argument('app_id'));
        $this->company = Companies::getById($this->argument('company_id'));
        $this->region = Regions::getById($this->argument('region_id'));
        $this->user = UsersRepository::getUserOfAppById((int)$this->argument('user_id'), $this->app);
        $this->url = $this->argument('url');
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
            $this->error('Failed to download the file.');
        }
        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);
        $reader->setEscape('');

        $records = $reader->getRecords();
        $collection = collect($records);
        $app = $this->app;
        $company = $this->company;
        foreach ($collection as $order) {
            $kanvasOrder = Order::getByCustomField(CustomFieldEnum::WOOCOMMERCE_ORDER_ID->value, $order['order_reference'], $company);
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
                        'weight' => 0,
                    ],
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

            try {
                $user = UsersRepository::getByEmail($order['email']);
            } catch (Exception $e) {
                $user = $this->user;
                // continue;
            }
            $dto = OrderDTO::from([
                'app' => $this->app,
                'region' => $this->region,
                'company' => $this->company,
                'people' => $people,
                'user' => $user,
                'token' => $order['key'],
                'orderNumber' => '',
                'total' => (float) $total,
                'taxes' => 0.0,
                'totalDiscount' => 0.0,
                'totalShipping' => 0.0,
                'status' => OrderStatusEnum::COMPLETED->value,
                'checkoutToken' => '',
                'currency' => Currencies::getByCode('USD'),
                'items' => $items,
            ]);
            $action = new CreateOrderAction($dto);
            $action->disableWorkflow();
            $kanvasOrder = $action->execute();
            $kanvasOrder->set(CustomFieldEnum::WOOCOMMERCE_ORDER_ID->value, $order['order_reference']);
            $esim = Esim::from([
                'lpaCode' => $order['lpa_code'],
                'iccid' => $order['region'],
                'status' => $order['activation_status'],
                'quantity' => $items->count(),
                'pricePerUnit' => $items->first()->price,
                'type' => 'bundle',
                'plan' => $dto->items->first()->sku,
                'smdpAddress' => $order['smdp_address'],
                'matchingId' => $order['matching_id'],
                'firstInstalledDateTime' => strtotime($order['date_from']),
                'orderReference' => $kanvasOrder->getId(),
                'qrCode' => CreateEsimOrderAction::generateQrCode($order['lpa_code']),
                'esimStatus' => ESimStatus::from([
                    'id' => $order['activation'],
                    'callTypeGroup' => 'data',
                    'initialQuantity' => FileSizeConverter::toBytes($items->first()->sku),
                    'remainingQuantity' => FileSizeConverter::toBytes($items->first()->sku),
                    'assignmentDateTime' => $order['date_from'],
                    'assignmentReference' => $order['lpa_code'],
                    'bundleState' => json_decode($order['activation'])->status,
                    'unlimited' => $kanvasOrder->items()->first()->variant->getAttributeBySlug('variant-type')?->value === PlanTypeEnum::UNLIMITED,
                ]),
                'label' => $order['label']
             ]);
            $response = [
                'success' => true,
                'data' => $esim->toArray(),
                'esim_status' => $esim->esimStatus->toArray(),
                'order_id' => $kanvasOrder->id,
                'order' => $kanvasOrder->toArray()
            ];
            $messageType = (new CreateMessageTypeAction(
                new MessageTypeInput(
                    $app->getId(),
                    0,
                    'esim',
                    'esim',
                )
            ))->execute();
            $createMessage = new CreateMessageAction(
                new MessageInput(
                    $app,
                    $kanvasOrder->company,
                    $kanvasOrder->user,
                    $messageType,
                    $response
                ),
                SystemModulesRepository::getByModelName(Order::class, $app),
                $kanvasOrder->getId()
            );

            $message = $createMessage->execute();

            $this->info("Order created: {$kanvasOrder->order_number}\n");
        }
    }
}
