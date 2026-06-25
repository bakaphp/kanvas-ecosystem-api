<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class ContactBounceStatusTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function test_bounce_status_lifecycle_and_deliverability(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $email = 'bounce-' . uniqid() . '@intras.test';
        $people->addEmail($email, 0, 0);

        $contact = $people->contacts()
            ->where('contacts_types_id', ContactTypeEnum::EMAIL->value)
            ->where('value', $email)
            ->firstOrFail();

        // Fresh contact defaults to valid + deliverable.
        $this->assertSame(ContactValidationStatusEnum::VALID, $contact->validation_status);
        $this->assertTrue($contact->isDeliverable());

        // Soft bounce is recoverable — still deliverable.
        $contact->markBounce(permanent: false);
        $this->assertSame(ContactValidationStatusEnum::SOFT_BOUNCE, $contact->validation_status);
        $this->assertNotNull($contact->bounced_at);
        $this->assertTrue($contact->isDeliverable(), 'Soft bounce stays deliverable.');

        // Hard bounce is permanent — not deliverable, flagged for replacement.
        $contact->markBounce(permanent: true);
        $this->assertSame(ContactValidationStatusEnum::HARD_BOUNCE, $contact->validation_status);
        $this->assertFalse($contact->isDeliverable());

        // The deliverable scope excludes it.
        $deliverableIds = Contact::query()
            ->where('peoples_id', $people->getId())
            ->deliverable()
            ->pluck('id');
        $this->assertNotContains($contact->id, $deliverableIds);

        // Apollo supplies a fresh email → reset to valid clears the flag.
        $contact->markValid();
        $this->assertSame(ContactValidationStatusEnum::VALID, $contact->validation_status);
        $this->assertNull($contact->bounced_at);
        $this->assertTrue($contact->isDeliverable());
    }

    public function test_invalid_is_a_permanent_failure(): void
    {
        $this->assertTrue(ContactValidationStatusEnum::INVALID->isPermanentFailure());
        $this->assertTrue(ContactValidationStatusEnum::HARD_BOUNCE->isPermanentFailure());
        $this->assertFalse(ContactValidationStatusEnum::SOFT_BOUNCE->isPermanentFailure());
        $this->assertFalse(ContactValidationStatusEnum::VALID->isPermanentFailure());
    }
}
