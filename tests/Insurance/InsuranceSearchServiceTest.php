<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Contracts\CompanyInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Humano\Providers\HumanoProvider;
use Kanvas\Connectors\Humano\Services\HumanoService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Enums\InsuranceSearchFailureReasonEnum;
use Kanvas\Insurance\Models\InsuranceVehicleModelMap;
use Kanvas\Insurance\Models\Insurer;
use Kanvas\Insurance\Services\InsuranceComparatorService;
use Kanvas\Insurance\Services\InsuranceSearchService;
use Kanvas\Insurance\Services\InsuranceVehicleModelResolver;
use Kanvas\Insurance\Services\VehicleKeyNormalizer;
use Laravel\Octane\Exceptions\TaskTimeoutException;
use Mockery;
use RuntimeException;
use Tests\Insurance\Concerns\BuildsSearchCatalog;
use Tests\Insurance\Fakes\FakeQuoteTaskRunner;
use Tests\TestCase;

class InsuranceSearchServiceTest extends TestCase
{
    use DatabaseTransactions;
    use BuildsSearchCatalog;

    protected $connectionsToTransact = [null, 'commerce', 'insurance'];

    private function service(
        ?FakeQuoteTaskRunner $runner = null,
        ?InsuranceVehicleModelResolver $resolver = null,
    ): InsuranceSearchService {
        return new InsuranceSearchService(
            $runner ?? new FakeQuoteTaskRunner(),
            $resolver ?? new InsuranceVehicleModelResolver(),
            new InsuranceComparatorService(),
        );
    }

    public function testAnInsurerThatFailsDoesNotTakeTheRestDownAndIsReported(): void
    {
        $company = $this->searchCompany();
        $suffix = uniqid();

        $this->bindFakeProvider('ok_' . $suffix);
        $this->bindFakeProvider('boom_' . $suffix, fn () => throw new RuntimeException('403 secret upstream detail'));
        $this->bindFakeProvider('rejects_' . $suffix, fn () => throw new ValidationException('Vehicle not insurable'));

        $okInsurer = $this->seedInsurer($company, 'ok_' . $suffix);
        $this->seedPlan($okInsurer, 'A');
        $this->seedPlan($okInsurer, 'B', ['sponsored' => true]);
        $boom = $this->seedInsurer($company, 'boom_' . $suffix);
        $this->seedPlan($boom, 'A');
        $rejects = $this->seedInsurer($company, 'rejects_' . $suffix);
        $this->seedPlan($rejects, 'A');

        $result = $this->service()->execute($this->searchInput($company));

        $this->assertCount(2, $result->entries);
        $this->assertTrue($result->entries[0]->sponsored);

        $reasons = [];
        foreach ($result->failures as $failure) {
            $reasons[$failure->insurerId] = $failure;
        }

        $this->assertCount(2, $reasons);
        $this->assertSame(InsuranceSearchFailureReasonEnum::UNAVAILABLE, $reasons[$boom->getId()]->reason);
        $this->assertStringNotContainsString('secret', $reasons[$boom->getId()]->message);
        $this->assertSame(InsuranceSearchFailureReasonEnum::REJECTED, $reasons[$rejects->getId()]->reason);
        $this->assertSame(InsuranceSearchFailureReasonEnum::REJECTED->customerMessage(), $reasons[$rejects->getId()]->message);
        $this->assertStringNotContainsString('insurable', $reasons[$rejects->getId()]->message);
    }

    public function testAQuoteTheInsurerRefusesIsAFailureNotAnEntry(): void
    {
        $company = $this->searchCompany();
        $name = 'refuses_' . uniqid();
        $this->bindFakeProvider(
            $name,
            fn (): QuoteResult => new QuoteResult(success: false, message: 'No cover for this model', quoteNumber: '')
        );
        $this->seedPlan($this->seedInsurer($company, $name), 'A');

        $result = $this->service()->execute($this->searchInput($company));

        $this->assertSame([], $result->entries);
        $this->assertCount(1, $result->failures);
        $this->assertSame(InsuranceSearchFailureReasonEnum::REJECTED->customerMessage(), $result->failures[0]->message);
    }

    public function testAnInsurerThatDoesNotAnswerInTimeIsReportedAsATimeout(): void
    {
        $company = $this->searchCompany();
        $fast = 'fast_' . uniqid();
        $slow = 'slow_' . uniqid();
        $this->bindFakeProvider($fast);
        $slowProvider = $this->bindFakeProvider($slow);
        $this->seedPlan($this->seedInsurer($company, $fast), 'A');
        $slowInsurer = $this->seedInsurer($company, $slow);
        $this->seedPlan($slowInsurer, 'A');

        $runner = new FakeQuoteTaskRunner(fn (string $key): bool => str_starts_with($key, $slowInsurer->getId() . ':'));

        $result = $this->service($runner)->execute($this->searchInput($company));

        $this->assertCount(1, $result->entries);
        $this->assertCount(1, $result->failures);
        $this->assertSame(InsuranceSearchFailureReasonEnum::TIMEOUT, $result->failures[0]->reason);
        $this->assertSame($slowInsurer->getId(), $result->failures[0]->insurerId);
        $this->assertSame([], $slowProvider->calls);
        $this->assertSame(8000, $runner->lastTimeoutMs);
    }

    public function testAnInsurerWhoseModelIdIsUnmappedIsSkippedAndNeverCalled(): void
    {
        $company = $this->searchCompany();
        $name = 'ids_' . uniqid();
        $provider = $this->bindFakeProvider($name, needsVehicleModelId: true);
        $this->seedPlan($this->seedInsurer($company, $name), 'A');

        $resolver = Mockery::mock(InsuranceVehicleModelResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn(null);

        $result = $this->service(resolver: $resolver)->execute($this->searchInput($company));

        $this->assertSame([], $result->entries);
        $this->assertSame([], $result->failures);
        $this->assertSame([], $provider->calls);
    }

    public function testAMappedModelIdIsPassedToTheAdapter(): void
    {
        $company = $this->searchCompany();
        $name = 'ids_' . uniqid();
        $provider = $this->bindFakeProvider($name, needsVehicleModelId: true);
        $this->seedPlan($this->seedInsurer($company, $name), 'A');

        $resolver = Mockery::mock(InsuranceVehicleModelResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn('77');

        $result = $this->service(resolver: $resolver)->execute($this->searchInput($company));

        $this->assertCount(1, $result->entries);
        $this->assertSame('77', $provider->calls[0]['vehicleModelId']);
    }

    public function testOnlyPlansOfTheRequestedCategoryAreQuoted(): void
    {
        $company = $this->searchCompany();
        $name = 'cat_' . uniqid();
        $provider = $this->bindFakeProvider($name);
        $insurer = $this->seedInsurer($company, $name);
        $this->seedPlan($insurer, 'LAW1', ['type' => 'law']);
        $this->seedPlan($insurer, 'FULL1', ['type' => 'full']);

        $result = $this->service()->execute($this->searchInput($company, InsuranceCoverageCategoryEnum::LAW));

        $this->assertCount(1, $result->entries);
        $this->assertSame([['plan' => 'LAW1', 'vehicleModelId' => null]], $provider->calls);
    }

    public function testASecondIdenticalSearchIsServedFromCacheWithoutCallingTheInsurer(): void
    {
        $company = $this->searchCompany();
        $name = 'cache_' . uniqid();
        $provider = $this->bindFakeProvider($name);
        $this->seedPlan($this->seedInsurer($company, $name), 'A');
        $input = $this->searchInput($company);

        $first = $this->service()->execute($input);
        $second = $this->service()->execute($input);

        $this->assertCount(1, $provider->calls);
        $this->assertCount(1, $second->entries);
        $this->assertSame($first->entries[0]->quote->quoteNumber, $second->entries[0]->quote->quoteNumber);
    }

    public function testADifferentVehicleBypassesTheCache(): void
    {
        $company = $this->searchCompany();
        $name = 'cache_' . uniqid();
        $provider = $this->bindFakeProvider($name);
        $this->seedPlan($this->seedInsurer($company, $name), 'A');

        $this->service()->execute($this->searchInput($company));
        $this->service()->execute($this->searchInput($company));

        $this->assertCount(2, $provider->calls);
    }

    public function testFailuresAreNeverCached(): void
    {
        $company = $this->searchCompany();
        $name = 'flaky_' . uniqid();
        $provider = $this->bindFakeProvider(
            $name,
            function (string $planCode, int $call): QuoteResult {
                if ($call === 1) {
                    throw new RuntimeException('upstream 403');
                }

                return $this->quoteResult('Q-OK');
            }
        );
        $this->seedPlan($this->seedInsurer($company, $name), 'A');
        $input = $this->searchInput($company);

        $first = $this->service()->execute($input);
        $second = $this->service()->execute($input);
        $third = $this->service()->execute($input);

        $this->assertCount(1, $first->failures);
        $this->assertSame([], $first->entries);
        $this->assertCount(1, $second->entries);
        $this->assertCount(1, $third->entries);
        $this->assertCount(2, $provider->calls);
    }

    public function testCommissionFallsBackToThePlanRateWhenTheInsurerDoesNotPublishOne(): void
    {
        $company = $this->searchCompany();
        $name = 'rate_' . uniqid();
        $this->bindFakeProvider($name, fn (): QuoteResult => $this->quoteResult('Q-R', 116.0));
        $this->seedPlan($this->seedInsurer($company, $name), 'A', ['commission_rate' => 10]);

        $result = $this->service()->execute($this->searchInput($company));

        $this->assertEqualsWithDelta(10.0, $result->entries[0]->commission, 0.001);
    }

    public function testAnotherTenantsInsurersAreNeverSearched(): void
    {
        $owner = $this->createUser()->getCurrentCompany();
        $other = $this->createUser()->getCurrentCompany();
        $this->pinPlatformCompany(0);
        $name = 'tenant_' . uniqid();
        $provider = $this->bindFakeProvider($name);
        $this->seedPlan($this->seedInsurer($owner, $name), 'A');

        $result = $this->service()->execute($this->searchInput($other));

        $this->assertSame([], $result->entries);
        $this->assertSame([], $provider->calls);
        $this->assertSame(app(Apps::class)->getId(), $this->searchInput($other)->app->getId());
    }

    public function testATimedOutChunkLosesOnlyItself(): void
    {
        config(['insurance.task_workers' => 1]);
        $company = $this->searchCompany();
        $suffix = uniqid();
        $insurers = [];

        foreach (['a', 'b', 'c'] as $letter) {
            $this->bindFakeProvider($letter . '_' . $suffix);
            $insurers[$letter] = $this->seedInsurer($company, $letter . '_' . $suffix);
            $this->seedPlan($insurers[$letter], 'P');
        }

        $slowPrefix = $insurers['b']->getId() . ':';
        $runner = new FakeQuoteTaskRunner(
            throwsFor: function (array $keys) use ($slowPrefix): void {
                if (str_starts_with($keys[0], $slowPrefix)) {
                    throw TaskTimeoutException::after(8000);
                }
            }
        );

        $result = $this->service($runner)->execute($this->searchInput($company));

        $this->assertCount(2, $result->entries);
        $this->assertCount(1, $result->failures);
        $this->assertSame(InsuranceSearchFailureReasonEnum::TIMEOUT, $result->failures[0]->reason);
        $this->assertSame($insurers['b']->getId(), $result->failures[0]->insurerId);
    }

    public function testADispatchErrorIsUnavailableNotATimeout(): void
    {
        $company = $this->searchCompany();
        $name = 'dispatch_' . uniqid();
        $this->bindFakeProvider($name);
        $this->seedPlan($this->seedInsurer($company, $name), 'A');

        $runner = new FakeQuoteTaskRunner(
            throwsFor: fn (array $keys) => throw new RuntimeException('serialization exploded')
        );

        $result = $this->service($runner)->execute($this->searchInput($company));

        $this->assertSame([], $result->entries);
        $this->assertCount(1, $result->failures);
        $this->assertSame(InsuranceSearchFailureReasonEnum::UNAVAILABLE, $result->failures[0]->reason);
        $this->assertStringNotContainsString('serialization', $result->failures[0]->message);
    }

    private function humanoUnderwriting(): array
    {
        return [
            'ip_address' => '10.0.0.1',
            'birth_date' => '1993-08-24',
            'marital_status' => 'single',
            'sex' => 'male',
            'usage' => 'private',
            'circulation_zone' => 'santo_domingo',
        ];
    }

    private function bindHumano(?array &$captured = null): void
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('quote')->andReturnUsing(function ($request) use (&$captured): array {
            $captured = $request->toArray();

            return ['cotizaciones' => [[
                'numeroCotizacion' => 'H-1',
                'montoPrimaProrrata' => 1000,
                'montoComponenteProrrata' => 160,
                'montoTotalProrrata' => 1160,
            ]]];
        });
        $humano = new HumanoProvider(
            app: app(Apps::class),
            company: Mockery::mock(CompanyInterface::class),
            service: $service,
        );
        $this->app->bind('insurance_provider.humano', fn (): HumanoProvider => $humano);
    }

    private function mapHumanoVehicle(Insurer $insurer, string $model): void
    {
        InsuranceVehicleModelMap::query()->create([
            'apps_id' => $insurer->apps_id,
            'insurers_id' => $insurer->getId(),
            'plan_code' => 'mi_auto_full',
            'brand_key' => 'toyota',
            'model_key' => VehicleKeyNormalizer::normalize($model),
            'version_key' => 'le',
            'year' => 0,
            'external_brand_id' => '2',
            'external_model_id' => '5',
            'external_version_id' => '7',
            'source' => 'manual',
            'active' => true,
        ]);
    }

    public function testHumanoIsQuotedWhenTheVehicleIsMappedAndAVersionIsSupplied(): void
    {
        $company = $this->searchCompany();
        $captured = null;
        $this->bindHumano($captured);
        $insurer = $this->seedInsurer($company, 'humano');
        $this->seedPlan($insurer, 'mi_auto_full');
        $model = 'Corolla ' . uniqid();
        $this->mapHumanoVehicle($insurer, $model);

        $result = $this->service()->execute(
            $this->searchInput($company, model: $model, version: 'LE', underwriting: $this->humanoUnderwriting())
        );

        $this->assertCount(1, $result->entries);
        $this->assertSame([], $result->failures);
        $this->assertSame('H-1', $result->entries[0]->quote->quoteNumber);
        $datos = array_column($captured['datos'], 'valorDato', 'codigoDato');
        $this->assertSame('2', $datos[250000]);
        $this->assertSame('5', $datos[250001]);
        $this->assertSame('7', $datos[250004]);
        $this->assertSame('24-08-1993', $datos[250069]);
    }

    public function testHumanoIsSkippedSilentlyWhenNoVersionIsSupplied(): void
    {
        $company = $this->searchCompany();
        $this->bindHumano();
        $insurer = $this->seedInsurer($company, 'humano');
        $this->seedPlan($insurer, 'mi_auto_full');
        $model = 'Corolla ' . uniqid();
        $this->mapHumanoVehicle($insurer, $model);

        $result = $this->service()->execute(
            $this->searchInput($company, model: $model, underwriting: $this->humanoUnderwriting())
        );

        $this->assertSame([], $result->entries);
        $this->assertSame([], $result->failures);
    }

    public function testHumanoIsSkippedSilentlyWhenTheVehicleIsUnmapped(): void
    {
        $company = $this->searchCompany();
        $this->bindHumano();
        $this->seedPlan($this->seedInsurer($company, 'humano'), 'mi_auto_full');

        $result = $this->service()->execute(
            $this->searchInput($company, version: 'LE', underwriting: $this->humanoUnderwriting())
        );

        $this->assertSame([], $result->entries);
        $this->assertSame([], $result->failures);
    }

    public function testHumanoIsSkippedSilentlyWhenTheMappingBelongsToAnotherPlan(): void
    {
        $company = $this->searchCompany();
        $this->bindHumano();
        $insurer = $this->seedInsurer($company, 'humano');
        $this->seedPlan($insurer, 'mi_auto_premier');
        $model = 'Corolla ' . uniqid();
        $this->mapHumanoVehicle($insurer, $model);

        $result = $this->service()->execute(
            $this->searchInput($company, model: $model, version: 'LE', underwriting: $this->humanoUnderwriting())
        );

        $this->assertSame([], $result->entries);
        $this->assertSame([], $result->failures);
    }

    public function testASkippedHumanoDoesNotAffectAnIdBasedInsurer(): void
    {
        $company = $this->searchCompany();
        $this->bindHumano();
        $this->seedPlan($this->seedInsurer($company, 'humano'), 'mi_auto_full');
        $name = 'ids_' . uniqid();
        $provider = $this->bindFakeProvider($name, needsVehicleModelId: true);
        $this->seedPlan($this->seedInsurer($company, $name), 'A');
        $resolver = Mockery::mock(InsuranceVehicleModelResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn('77');

        $result = $this->service(resolver: $resolver)->execute(
            $this->searchInput($company, version: 'LE', underwriting: $this->humanoUnderwriting())
        );

        $this->assertCount(1, $result->entries);
        $this->assertSame('77', $provider->calls[0]['vehicleModelId']);
    }

    public function testIncompleteUnderwritingIsReportedAsMissingInformationNotAnOutage(): void
    {
        $company = $this->searchCompany();
        $captured = null;
        $this->bindHumano($captured);
        $insurer = $this->seedInsurer($company, 'humano');
        $this->seedPlan($insurer, 'mi_auto_full');
        $model = 'Corolla ' . uniqid();
        $this->mapHumanoVehicle($insurer, $model);

        $result = $this->service()->execute(
            $this->searchInput($company, model: $model, version: 'LE', underwriting: ['ip_address' => '10.0.0.1'])
        );

        $this->assertSame([], $result->entries);
        $this->assertCount(1, $result->failures);
        $this->assertSame(InsuranceSearchFailureReasonEnum::INCOMPLETE_INFORMATION, $result->failures[0]->reason);
        $this->assertSame('More information is needed to quote this vehicle', $result->failures[0]->message);
        $this->assertNull($captured);
    }

    public function testHumanoIsLeftOutSilentlyWhenTheSearchSwitchIsOff(): void
    {
        config(['insurance.humano.search_enabled' => false]);
        $company = $this->searchCompany();
        $captured = null;
        $this->bindHumano($captured);
        $insurer = $this->seedInsurer($company, 'humano');
        $this->seedPlan($insurer, 'mi_auto_full');
        $model = 'Corolla ' . uniqid();
        $this->mapHumanoVehicle($insurer, $model);

        $result = $this->service()->execute(
            $this->searchInput($company, model: $model, version: 'LE', underwriting: $this->humanoUnderwriting())
        );

        $this->assertSame([], $result->entries);
        $this->assertSame([], $result->failures);
        $this->assertNull($captured);
    }
}
