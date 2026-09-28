<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Sales;

use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\CreateSampleOrderAction;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Spatie\LaravelData\DataCollection;

/**
 * Creates a $0 sample / giveaway sales order in Kanvas (Souk) — the "send a reviewer a free unit"
 * flow. Kanvas-first: it lands as a DRAFT, nothing is written to the ERP here; a human approves it and
 * the workflow pushes it to Acumatica. The customer is resolved by email (created if new); the SKU
 * must already be synced as a product.
 */
#[AgentTool(name: 'Create Sample Order', category: 'commerce')]
class CreateSampleOrderTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_sample_order',
            description: 'Create a $0 sample/giveaway sales order for a reviewer or contact. Provide the '
                . 'customer email + name, the product SKU, and quantity. It is created as a DRAFT in Kanvas — '
                . 'it is NOT sent to the ERP until a human approves it. Returns created=false with a reason if '
                . 'the SKU is not synced.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'customer_email',
                type: PropertyType::STRING,
                description: 'Email of the reviewer/customer receiving the sample.',
                required: true,
            ),
            new ToolProperty(
                name: 'customer_name',
                type: PropertyType::STRING,
                description: 'Full name of the reviewer/customer.',
                required: true,
            ),
            new ToolProperty(
                name: 'sku',
                type: PropertyType::STRING,
                description: 'The product SKU to send (must be a synced product).',
                required: true,
            ),
            new ToolProperty(
                name: 'quantity',
                type: PropertyType::INTEGER,
                description: 'How many units. Defaults to 1.',
                required: false,
            ),
            new ToolProperty(
                name: 'note',
                type: PropertyType::STRING,
                description: 'Optional note (e.g. "YouTube review unit").',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $customer_email,
        string $customer_name,
        string $sku,
        ?int $quantity = null,
        ?string $note = null,
    ): array {
        $app = $this->app;
        $user = $this->user;
        $company = $this->company;
        $quantity = max(1, $quantity ?? 1);

        $variant = Variants::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('sku', $sku)
            ->notDeleted()
            ->first();

        if ($variant === null) {
            return ['created' => false, 'reason' => "No product with SKU {$sku} is synced — sync it first."];
        }

        $region = Regions::fromApp($app)->fromCompany($company)->notDeleted()->first();

        if ($region === null) {
            return ['created' => false, 'reason' => 'No sales region is configured for this company.'];
        }

        $people = PeoplesRepository::getByEmail($customer_email, $company, $app)
            ?? $this->createPerson($app, $user, $customer_name, $customer_email);

        $order = new CreateSampleOrderAction(
            app: $app,
            company: $company,
            user: $user,
            region: $region,
            people: $people,
            currency: Currencies::getByCode('USD'),
            lines: [['variant' => $variant, 'quantity' => $quantity]],
            note: $note,
        )->execute();

        return [
            'created' => true,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'customer' => $customer_name,
            'sku' => $variant->sku,
            'quantity' => $quantity,
            'next' => 'Draft created in Kanvas. It pushes to Acumatica only after a human approves it.',
        ];
    }

    private function createPerson(Apps $app, mixed $user, string $name, string $email): People
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];
        $firstname = $parts[0] ?? $name;
        $lastname = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        return new CreatePeopleAction(
            PeopleData::from([
                'app' => $app,
                'branch' => $user->getCurrentBranch(),
                'user' => $user,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'contacts' => Contact::collect(
                    [['value' => $email, 'contacts_types_id' => ContactTypeEnum::EMAIL->value]],
                    DataCollection::class,
                ),
                'address' => Address::collect([], DataCollection::class),
                'runWorkflow' => false,
            ]),
        )->execute();
    }
}
