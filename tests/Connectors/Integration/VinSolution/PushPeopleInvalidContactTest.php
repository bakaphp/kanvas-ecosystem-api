<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushPeopleAction;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class PushPeopleInvalidContactTest extends TestCase
{
    public function testInvalidEmailIsFlaggedAndFilteredFromPushPayload(): void
    {
        $people = $this->createPerson(emails: [
            'rebecca.dagouop@aol.com',
            'rebecca.k.dago@gmail.com',
        ]);

        $action = $this->makeAction($people);

        // VinSolutions rejects the whole PUT because one address fails its verifier.
        $exception = $this->vinSolutionRejection('rebecca.dagouop@aol.com is not valid.  Failed Format validation');
        $flagged = $action->flagRejectedContactsFromErrorForTest($exception);

        $this->assertSame(1, $flagged, 'exactly the rejected email is flagged');

        $rejected = $people->getEmails()->firstWhere('value', 'rebecca.dagouop@aol.com');
        $this->assertSame(ContactValidationStatusEnum::INVALID, $rejected->validation_status);

        $addresses = array_column($action->prepareEmailsForTest($people->fresh(), false), 'EmailAddress');
        $this->assertNotContains('rebecca.dagouop@aol.com', $addresses);
        $this->assertContains('rebecca.k.dago@gmail.com', $addresses);
    }

    public function testInvalidPhoneIsFlaggedAndFilteredFromPushPayload(): void
    {
        // 612450182 is 9 digits — VinSolutions rejects it as "Not a valid Phone Number".
        $people = $this->createPerson(phones: ['612450182', '2025550111']);

        $action = $this->makeAction($people);

        $exception = $this->vinSolutionRejection('Not a valid Phone Number: 612450182.');
        $flagged = $action->flagRejectedContactsFromErrorForTest($exception);

        $this->assertSame(1, $flagged, 'exactly the rejected phone is flagged');

        $rejected = $people->getPhones()->firstWhere('value', '612450182');
        $this->assertSame(ContactValidationStatusEnum::INVALID, $rejected->validation_status);

        $numbers = array_column($action->preparePhonesForTest($people->fresh(), false), 'Number');
        $this->assertNotContains('612450182', $numbers);
        $this->assertContains('2025550111', $numbers);
    }

    public function testUnrelatedClientErrorFlagsNothing(): void
    {
        $people = $this->createPerson(emails: ['someone@example.com']);
        $action = $this->makeAction($people);

        $exception = $this->vinSolutionRejection('Dealer not authorized', class: 'System.Exception');

        $this->assertSame(
            0,
            $action->flagRejectedContactsFromErrorForTest($exception),
            'a non-validation 400 flags nothing, so it still surfaces to Sentry'
        );
        $this->assertSame(
            ContactValidationStatusEnum::VALID,
            $people->getEmails()->first()->validation_status,
            'unrelated errors never touch contact validation state'
        );
    }

    private function vinSolutionRejection(string $message, string $class = 'System.ArgumentException'): ClientException
    {
        $body = json_encode(['ClassName' => $class, 'Message' => $message]);

        return new ClientException(
            'Client error',
            new Request('PUT', '/gateway/v1/contact/901506093'),
            new Response(400, [], $body)
        );
    }

    private function createPerson(array $emails = [], array $phones = []): People
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $people->contacts()->delete();

        $weight = 100;
        foreach ($emails as $email) {
            $people->addEmail($email, 0, $weight--);
        }
        foreach ($phones as $phone) {
            $people->addPhone($phone, 0, $weight--);
        }

        return $people->fresh();
    }

    private function makeAction(People $people): PushPeopleAction
    {
        // Bypass the parent constructor (it resolves live VinSolutions credentials); we only
        // exercise the flag + payload-building logic, which needs just the People model.
        return new class ($people) extends PushPeopleAction {
            public function __construct(People $people)
            {
                $this->people = $people;
            }

            public function flagRejectedContactsFromErrorForTest(ClientException $e): int
            {
                return $this->flagRejectedContactsFromError($e);
            }

            public function prepareEmailsForTest(People $people, bool $isNew): array
            {
                return $this->prepareEmails($people, $isNew);
            }

            public function preparePhonesForTest(People $people, bool $isNew): array
            {
                return $this->preparePhones($people, $isNew);
            }
        };
    }
}
