<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\Defaults;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\Actions\CreatePeopleFromUserAction;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Customers\DataTransferObject\Contact as ContactData;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use PHPUnit\Framework\Attributes\Group;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * Serial: the flag is an app/company setting, and `HashTableTrait::set()` writes it to Redis plus the
 * `ecosystem` connection — neither rolled back by a test transaction. Flipping it in the parallel lane
 * would turn duplicate contacts on for every other process creating people in the same tenant.
 */
#[Group('serial')]
final class PeopleAllowDuplicateContactsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    protected function tearDown(): void
    {
        app(Apps::class)->del($this->flagKey());
        auth()->user()->getCurrentCompany()->del($this->flagKey());

        parent::tearDown();
    }

    public function testWithTheFlagNowhereTheSecondContactFoldsIntoTheExistingPerson(): void
    {
        [$first, $second] = $this->createTwoPeopleSharingAnEmail();

        $this->assertSame($first->getId(), $second->getId());
    }

    public function testAppLevelFlagAllowsDuplicateContacts(): void
    {
        app(Apps::class)->set($this->flagKey(), 1);

        [$first, $second] = $this->createTwoPeopleSharingAnEmail();

        $this->assertNotSame($first->getId(), $second->getId());
    }

    public function testCompanyLevelFlagAllowsDuplicateContactsWhenTheAppIsSilent(): void
    {
        auth()->user()->getCurrentCompany()->set($this->flagKey(), 1);

        [$first, $second] = $this->createTwoPeopleSharingAnEmail();

        $this->assertNotSame($first->getId(), $second->getId());
    }

    public function testTheAppOverridesTheCompanyWhenBothAreSet(): void
    {
        app(Apps::class)->set($this->flagKey(), 0);
        auth()->user()->getCurrentCompany()->set($this->flagKey(), 1);

        [$first, $second] = $this->createTwoPeopleSharingAnEmail();

        $this->assertSame($first->getId(), $second->getId());
    }

    public function testTheSignupPathLinksThePersonToTheProfileWhenDuplicatesAreNotAllowed(): void
    {
        $people = $this->createPersonFromUser();

        $this->assertSame($people->getId(), (int) auth()->user()->getAppProfile(app(Apps::class))->people_id);
    }

    public function testTheAppLevelFlagKeepsTheSignupPathFromLinkingThePersonToTheProfile(): void
    {
        $app = app(Apps::class);
        $linkedBefore = (int) auth()->user()->getAppProfile($app)->people_id;

        $app->set($this->flagKey(), 1);

        $people = $this->createPersonFromUser();

        $linkedAfter = (int) auth()->user()->getAppProfile($app)->people_id;
        $this->assertSame($linkedBefore, $linkedAfter);
        $this->assertNotSame($people->getId(), $linkedAfter);
    }

    public function testTheResolverReadsTheAppBeforeTheCompany(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $this->assertNull(Defaults::ALLOW_DUPLICATE_CONTACTS->getFromAppOrCompany($app, $company));

        $company->set($this->flagKey(), 1);
        $this->assertSame(1, (int) Defaults::ALLOW_DUPLICATE_CONTACTS->getFromAppOrCompany($app, $company));

        $app->set($this->flagKey(), 0);
        $this->assertSame(0, (int) Defaults::ALLOW_DUPLICATE_CONTACTS->getFromAppOrCompany($app, $company));
    }

    private function flagKey(): string
    {
        return (string) Defaults::ALLOW_DUPLICATE_CONTACTS->getValue();
    }

    /**
     * @return array{People, People}
     */
    private function createTwoPeopleSharingAnEmail(): array
    {
        $email = 'dup-' . fake()->unique()->uuid() . '@kanvas.dev';

        return [
            $this->createPersonWithEmail($email),
            $this->createPersonWithEmail($email),
        ];
    }

    private function createPersonFromUser(): People
    {
        $user = auth()->user();

        return new CreatePeopleFromUserAction(app(Apps::class), $user->getCurrentCompany()->branch, $user)->execute();
    }

    private function createPersonWithEmail(string $email): People
    {
        $user = auth()->user();

        $action = new CreatePeopleAction(
            new PeopleData(
                app: app(Apps::class),
                branch: $user->getCurrentCompany()->branch,
                user: $user,
                firstname: 'Duplicate',
                lastname: 'Contact' . fake()->unique()->uuid(),
                contacts: new DataCollection(ContactData::class, [
                    ContactData::from([
                        'value' => $email,
                        'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                        'weight' => 0,
                    ]),
                ]),
                address: new DataCollection(AddressData::class, []),
            ),
        );
        $action->runWorkflow = false;

        return $action->execute();
    }
}
