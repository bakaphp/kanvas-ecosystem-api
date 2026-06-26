<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mailgun\Actions\ValidatePeopleEmailAction;
use Kanvas\Connectors\Mailgun\DataTransferObject\EmailValidationResult;
use Kanvas\Connectors\Mailgun\Services\EmailValidationService;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

final class ValidatePeopleEmailActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence'];

    public function test_flags_undeliverable_email_and_records_the_ledger_event(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $email = 'dead-' . uniqid() . '@nowhere.test';
        $people->addEmail($email, 0, 0);

        $result = new ValidatePeopleEmailAction($people, $app, $this->fakeValidator('undeliverable'))->execute();

        $this->assertCount(1, $result['validated']);
        $this->assertSame('undeliverable', $result['validated'][0]['result']);

        $contact = $people->contacts()->where('value', $email)->firstOrFail();
        $this->assertSame(ContactValidationStatusEnum::HARD_BOUNCE, $contact->validation_status);
        $this->assertNotNull($contact->bounced_at);
        $this->assertFalse($contact->isDeliverable());

        $event = Event::query()
            ->where('event_type', 'people.email_validated')
            ->where('source_entity_id', $people->getId())
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'A people.email_validated ledger event should be recorded.');
        $this->assertSame(1, (int) ($event->payload['invalid_count'] ?? 0));
    }

    public function test_marks_a_deliverable_email_valid(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $email = 'live-' . uniqid() . '@intras.test';
        $people->addEmail($email, 0, 0);

        new ValidatePeopleEmailAction($people, $app, $this->fakeValidator('deliverable'))->execute();

        $contact = $people->contacts()->where('value', $email)->firstOrFail();
        $this->assertSame(ContactValidationStatusEnum::VALID, $contact->validation_status);
        $this->assertNull($contact->bounced_at);
        $this->assertTrue($contact->isDeliverable());
    }

    public function test_catch_all_leaves_the_contact_untouched(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $email = 'maybe-' . uniqid() . '@catchall.test';
        $people->addEmail($email, 0, 0);

        new ValidatePeopleEmailAction($people, $app, $this->fakeValidator('catch_all'))->execute();

        $contact = $people->contacts()->where('value', $email)->firstOrFail();
        $this->assertSame(ContactValidationStatusEnum::VALID, $contact->validation_status);
        $this->assertNull($contact->bounced_at);
    }

    private function fakeValidator(string $result): EmailValidationService
    {
        $dto = new EmailValidationResult('x@x.test', $result, 'low', false, false, []);

        return new class ($dto) extends EmailValidationService {
            public function __construct(private EmailValidationResult $dto)
            {
            }

            public function validate(string $email): EmailValidationResult
            {
                return $this->dto;
            }
        };
    }
}
