<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\VinSolution;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\VinSolution\Client;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DealerSettingsCompatibilityTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public static function validIds(): array
    {
        return [
            'integer' => [42],
            'numeric setting string' => ['42'],
            'leading zeros' => ['00042'],
        ];
    }

    #[DataProvider('validIds')]
    public function testDealerLookupNormalizesSettingIds(int|string $id): void
    {
        $app = $this->createMock(AppInterface::class);
        $client = Mockery::mock('overload:' . Client::class);
        $client->shouldReceive('__construct')->once()->with(0, 0, $app);
        $client->shouldReceive('get')->once()
            ->with('/gateway/v1/organization/dealers')
            ->andReturn(['Items' => [[
                'DealerId' => 42,
                'Name' => 'Test dealer',
                'City' => 'Test city',
                'State' => 'FL',
            ]]]);
        $dealer = Dealer::getById(id: $id, app: $app);

        $this->assertSame(42, $dealer->id);
    }

    #[DataProvider('validIds')]
    public function testUserLookupNormalizesSettingIds(int|string $id): void
    {
        $app = $this->createMock(AppInterface::class);
        $dealer = new Dealer(id: 42, name: 'Test dealer', city: 'Test city', state: 'FL');
        $client = Mockery::mock('overload:' . Client::class);
        $client->shouldReceive('__construct')->once()->with(42, 0, $app);
        $client->shouldReceive('get')->once()
            ->with('/gateway/v1/tenant/user/id/42?dealerId=42')
            ->andReturn([
                'UserId' => 42,
                'UserGroup' => null,
                'UserTypes' => [],
                'EmailAddress' => 'test@example.com',
                'FullName' => 'Test User',
                'FirstName' => 'Test',
                'LastName' => 'User',
                'IlmAccess' => 'Salesperson',
            ]);

        $user = Dealer::getUser(dealer: $dealer, userId: $id, app: $app);

        $this->assertSame(42, $user->id);
    }

    public static function invalidIds(): array
    {
        return [
            'empty' => [''],
            'non-numeric' => ['invalid'],
            'decimal' => ['42.5'],
            'oauth-style identifier' => ['474083765751.10750363865122'],
            'scientific notation' => ['4.2e1'],
            'negative' => [-42],
            'zero' => [0],
            'string zero' => ['0'],
            'overflow' => [(string) PHP_INT_MAX . '0'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function testInvalidIdsAreRejectedBeforeApiAccess(int|string $id): void
    {
        $app = $this->createMock(AppInterface::class);
        $client = Mockery::mock('overload:' . Client::class);
        $client->shouldNotReceive('__construct');
        $dealer = new Dealer(id: 42, name: 'Test dealer', city: 'Test city', state: 'FL');

        foreach (['dealer', 'user'] as $lookup) {
            try {
                if ($lookup === 'dealer') {
                    Dealer::getById(id: $id, app: $app);
                } else {
                    Dealer::getUser(dealer: $dealer, userId: $id, app: $app);
                }

                $this->fail('Invalid ' . $lookup . ' ID must be rejected before API access');
            } catch (VinSolutionException $exception) {
                $this->assertSame(
                    'VinSolution ID must be a positive integer within the supported range',
                    $exception->getMessage()
                );
            }
        }
    }
}
