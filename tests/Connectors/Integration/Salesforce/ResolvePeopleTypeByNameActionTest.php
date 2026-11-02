<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\ResolvePeopleTypeByNameAction;
use Tests\TestCase;

final class ResolvePeopleTypeByNameActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testCreatesThePeopleTypeOnFirstSight(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $peopleType = new ResolvePeopleTypeByNameAction($app, $company, $user)->execute('Landlord');

        $this->assertSame('Landlord', $peopleType->name);
    }

    public function testReusesTheSamePeopleTypeInsteadOfCreatingADuplicate(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $first = new ResolvePeopleTypeByNameAction($app, $company, $user)->execute('Broker');
        $second = new ResolvePeopleTypeByNameAction($app, $company, $user)->execute('Broker');

        $this->assertSame($first->getId(), $second->getId());
    }
}
