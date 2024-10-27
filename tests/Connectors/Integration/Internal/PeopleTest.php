<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Internal;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Internal\Actions\ExtractCompanyNameFromEmailAction;
use Kanvas\Connectors\Internal\Activities\ExtractCompanyNameFromPeopleEmailActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

final class PeopleTest extends TestCase
{
    public function testExtractEmailCompanyName()
    {
        $email = 'test@kanvas.dev';

        $extractCompanyNameFromEmailAction = new ExtractCompanyNameFromEmailAction();

        $companyName = $extractCompanyNameFromEmailAction->execute($email);

        $this->assertNotNull($companyName);
    }

    public function testExtractEmailCompanyNamePublicProvider()
    {
        $email = fake()->email;

        $extractCompanyNameFromEmailAction = new ExtractCompanyNameFromEmailAction();
        $companyName = $extractCompanyNameFromEmailAction->execute($email);

        $this->assertNull($companyName);
    }

    public function testExtractEmailCompanyNameFromEmailActivity()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $exportActivity = new ExtractCompanyNameFromPeopleEmailActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $people = People::factory()->withCompanyId($company->getId())->withAppId($app->getId())->create();
        $people->addEmail('test@kanvas.dev');

        $app = app(Apps::class);

        $result = $exportActivity->execute(
            people: $people,
            app: $app,
            params: []
        );

        //$this->assertNotNull($result['organization_id']);
        //$this->assertNotNull($result['people_id']);
        $this->assertNotNull($people->organizations()->first()->name);
    }
}
