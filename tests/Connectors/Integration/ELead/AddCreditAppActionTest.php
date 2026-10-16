<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\AddCreditAppAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class AddCreditAppActionTest extends TestCase
{
    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }

    private function message(array $housing = [], array $financial = []): array
    {
        return [
            'data' => [
                'form' => [
                    'personal' => [
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'email' => 'john@example.com',
                    ],
                    'housing' => $housing,
                    'financial' => $financial,
                ],
            ],
        ];
    }

    public function testWithRelativesResidenceTypeMapsToFamily(): void
    {
        $result = (new AddCreditAppAction($this->makeLead()))->execute(
            $this->message(['residence_type' => 'With Relatives'])
        );

        $this->assertSame('4389', $result['data']['housingType']);
    }

    public function testUnknownResidenceTypeFallsBackToOther(): void
    {
        $result = (new AddCreditAppAction($this->makeLead()))->execute(
            $this->message(['residence_type' => 'Houseboat'])
        );

        $this->assertSame('4394', $result['data']['housingType']);
    }

    public function testMissingResidenceTypeKeepsOwnDefault(): void
    {
        $result = (new AddCreditAppAction($this->makeLead()))->execute($this->message());

        $this->assertSame('682', $result['data']['housingType']);
    }

    public function testUnknownEmploymentStatusAndStateDoNotThrow(): void
    {
        $result = (new AddCreditAppAction($this->makeLead()))->execute(
            $this->message(
                ['residence_type' => 'Rent', 'state' => ['code' => 'XX']],
                ['employment_status' => 'Freelancer']
            )
        );

        $this->assertSame('683', $result['data']['housingType']);
        $this->assertSame('4396', $result['data']['currentEmploymentStatusType']);
        $this->assertSame('10', $result['data']['state']);
    }
}
