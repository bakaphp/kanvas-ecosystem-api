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

final class PushPeopleInvalidEmailTest extends TestCase
{
    public function testInvalidEmailIsFlaggedAndFilteredFromPushPayload(): void
    {
        $people = $this->createPersonWithEmails([
            'rebecca.dagouop@aol.com',
            'rebecca.k.dago@gmail.com',
        ]);

        $action = $this->makeAction($people);

        // VinSolutions rejects the whole PUT because one address fails its verifier.
        $exception = $this->vinSolutionInvalidEmailException('rebecca.dagouop@aol.com');
        $flagged = $action->flagInvalidEmailsFromErrorForTest($exception);

        $this->assertSame(1, $flagged, 'exactly the rejected email is flagged');

        $rejected = $people->getEmails()
            ->firstWhere('value', 'rebecca.dagouop@aol.com');
        $this->assertSame(
            ContactValidationStatusEnum::INVALID,
            $rejected->validation_status,
            'the rejected address is marked INVALID'
        );

        // On the retry, the invalid address must no longer be in the payload,
        // while the valid one still is.
        $payload = $action->prepareEmailsForTest($people->fresh(), false);
        $addresses = array_column($payload, 'EmailAddress');

        $this->assertNotContains('rebecca.dagouop@aol.com', $addresses);
        $this->assertContains('rebecca.k.dago@gmail.com', $addresses);
    }

    public function testUnrelatedClientErrorFlagsNothing(): void
    {
        $people = $this->createPersonWithEmails(['someone@example.com']);
        $action = $this->makeAction($people);

        $exception = new ClientException(
            'Client error',
            new Request('PUT', '/gateway/v1/contact/1'),
            new Response(400, [], '{"ClassName":"System.Exception","Message":"Dealer not authorized"}')
        );

        $this->assertSame(
            0,
            $action->flagInvalidEmailsFromErrorForTest($exception),
            'a non-email-validation 400 flags nothing, so it still surfaces to Sentry'
        );
        $this->assertSame(
            ContactValidationStatusEnum::VALID,
            $people->getEmails()->first()->validation_status,
            'unrelated errors never touch contact validation state'
        );
    }

    private function vinSolutionInvalidEmailException(string $email): ClientException
    {
        $body = json_encode([
            'ClassName' => 'System.ArgumentException',
            'Message' => $email . ' is not valid.  Failed Format validation',
        ]);

        return new ClientException(
            'Client error',
            new Request('PUT', '/gateway/v1/contact/901506093'),
            new Response(400, [], $body)
        );
    }

    private function createPersonWithEmails(array $emails): People
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

        return $people->fresh();
    }

    private function makeAction(People $people): PushPeopleAction
    {
        // Bypass the parent constructor (it resolves live VinSolutions credentials); we only
        // exercise the email-flagging + payload-building logic, which needs just the People model.
        return new class ($people) extends PushPeopleAction {
            public function __construct(People $people)
            {
                $this->people = $people;
            }

            public function flagInvalidEmailsFromErrorForTest(ClientException $e): int
            {
                return $this->flagInvalidEmailsFromError($e);
            }

            public function prepareEmailsForTest(People $people, bool $isNew): array
            {
                return $this->prepareEmails($people, $isNew);
            }
        };
    }
}
