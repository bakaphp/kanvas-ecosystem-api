<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\CreatePeopleFromJidAction;
use Kanvas\Connectors\WaSender\Webhooks\ProcessWaSenderWebhookJob;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * Matching a WhatsApp JID back to an existing People record. The phone variants matter because
 * records predate consistent normalization — a miss here silently opens a duplicate customer.
 */
final class CreatePeopleFromJidActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    public function testElevenDigitJidMatchesAPersonStoredWithTenDigits(): void
    {
        $tenDigits = '809555' . random_int(1000, 9999);
        $existing = $this->peopleWithPhone($tenDigits);

        $resolved = new CreatePeopleFromJidAction(
            $this->receiver(),
            '1' . $tenDigits . '@s.whatsapp.net'
        )->execute();

        $this->assertSame($existing->getId(), $resolved?->getId());
    }

    public function testTenDigitJidMatchesAPersonStoredWithTheCountryCode(): void
    {
        $tenDigits = '809556' . random_int(1000, 9999);
        $existing = $this->peopleWithPhone('1' . $tenDigits);

        $resolved = new CreatePeopleFromJidAction(
            $this->receiver(),
            $tenDigits . '@s.whatsapp.net'
        )->execute();

        $this->assertSame($existing->getId(), $resolved?->getId());
    }

    public function testTheWhatsappJidCustomFieldIsStoredForLaterLookups(): void
    {
        $jid = '1809557' . random_int(1000, 9999) . '@s.whatsapp.net';

        $created = new CreatePeopleFromJidAction($this->receiver(), $jid, 'Ivan Peralta')->execute();

        $this->assertSame('Ivan Peralta', $created?->getName());
        $this->assertSame($jid, (string) $created?->get('whatsapp_jid'));
    }

    /**
     * Session hijack maps someone else's traffic onto an existing record; overwriting their name
     * and contacts with the hijacked JID's data would corrupt the real person.
     */
    public function testKeepExistingLeavesAKnownRecordUntouched(): void
    {
        $tenDigits = '809558' . random_int(1000, 9999);
        $existing = $this->peopleWithPhone($tenDigits, 'Original Name');

        $resolved = new CreatePeopleFromJidAction(
            $this->receiver(),
            '1' . $tenDigits . '@s.whatsapp.net',
            'Hijacked Name',
            keepExisting: true
        )->execute();

        $this->assertSame($existing->getId(), $resolved?->getId());
        $this->assertSame('Original Name', $resolved?->getName());
    }

    public function testGroupJidResolvesToNoPersonAtAll(): void
    {
        $this->assertNull(
            new CreatePeopleFromJidAction($this->receiver(), '15550001111-1700000000@g.us')->execute()
        );
    }

    private function peopleWithPhone(string $phone, string $name = 'Known Person'): People
    {
        /** @var Users $user */
        $user = auth()->user();
        $parts = explode(' ', $name, 2);

        return new CreatePeopleAction(
            new PeopleDTO(
                app: app(Apps::class),
                branch: $user->getCurrentCompany()->defaultBranch,
                user: $user,
                firstname: $parts[0],
                contacts: Contact::collect([
                    [
                        'value' => $phone,
                        'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                        'weight' => 100,
                    ],
                ], DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: $parts[1] ?? '',
            )
        )->execute();
    }

    private ?ReceiverWebhook $receiver = null;

    private function receiver(): ReceiverWebhook
    {
        if ($this->receiver !== null) {
            return $this->receiver;
        }

        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessWaSenderWebhookJob::class],
            ['name' => 'ProcessWaSenderWebhookJob'],
        );

        return $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->company($user->getCurrentCompany()->getId())
            ->user($user->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
                'is_active' => true,
            ]);
    }
}
