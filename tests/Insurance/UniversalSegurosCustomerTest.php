<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Connectors\UniversalSeguros\Mappers\ClienteMapper;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\Enums\InsuranceCustomerFieldEnum;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class UniversalSegurosCustomerTest extends TestCase
{
    private const STORED_VEHICLE = [
        'idModelo' => 77,
        'anio' => 2022,
        'esCeroKm' => false,
        'combustible' => 'Gasolina / Diesel',
        'cumpleRequisitos' => true,
        'esUsoParticularNoDeportivoNoPublico' => true,
        'valor' => 1000000,
    ];

    private const CUSTOMER = [
        'first_name' => 'Ana',
        'last_name' => 'Perez',
        'email' => 'ana@example.com',
        'phone' => '(809) 555-0100',
        'birth_date' => '1990-05-17',
        'sex' => 'female',
        'marital_status' => 'married',
        'document_type' => 'national_id',
        'document_number' => '00100000001',
        'nationality' => 'Dominicana',
        'occupation' => 'Ingeniera',
        'country_of_residence' => 'Republica Dominicana',
        'address_province' => 'Distrito Nacional',
        'address_municipality' => 'Santo Domingo de Guzman',
        'address_sector' => 'Naco',
        'address_building' => 'Torre 1',
        'address_street' => 'Calle 5',
        'politically_exposed' => false,
        'has_pep_relative' => false,
    ];

    private const VERIFIED_CLIENTE_KEYS = [
        'nombre', 'apellido', 'genero', 'fechaNacimiento', 'tipoDocumento', 'numeroDocumento', 'telefono',
        'nacionalidad', 'correo', 'estadoCivil', 'ocupacion', 'imagenPasaporteUrl', 'direccion', 'debidaDiligencia',
    ];

    private function provider(MockInterface $service): UniversalSegurosProvider
    {
        return new UniversalSegurosProvider(
            app: Mockery::mock(AppInterface::class),
            company: Mockery::mock(CompanyInterface::class),
            service: $service,
        );
    }

    public function testEveryRequiredFieldIsPartOfTheCanonicalCatalogue(): void
    {
        $required = ClienteMapper::requiredFields();

        $this->assertContainsOnlyInstancesOf(InsuranceCustomerFieldEnum::class, $required);
        $this->assertNotContains(InsuranceCustomerFieldEnum::NATIONALITY, $required);
        $this->assertContains(InsuranceCustomerFieldEnum::ADDRESS_PROVINCE, $required);
        $this->assertContains(InsuranceCustomerFieldEnum::POLITICALLY_EXPOSED, $required);
    }

    public function testTheClienteHasExactlyTheKeysOfTheVerifiedPostmanPayload(): void
    {
        $cliente = ClienteMapper::map(InsuranceCustomer::fromArray(self::CUSTOMER));

        $this->assertSame(self::VERIFIED_CLIENTE_KEYS, array_keys($cliente));
        $this->assertSame(['provincia', 'municipio', 'sector', 'edificio', 'calle'], array_keys($cliente['direccion']));
        $this->assertSame(
            ['politicamenteExpuesto', 'poseeUnFamiliarPep', 'cargo', 'nombreFamiliar', 'parentescoFamiliar', 'cargoFamiliar'],
            array_keys($cliente['debidaDiligencia'])
        );
        $this->assertFalse($cliente['debidaDiligencia']['politicamenteExpuesto']);
        $this->assertNull($cliente['imagenPasaporteUrl']);
    }

    public function testTheWireBodyCarriesTheVerifiedKeysAndNoLegacyExtras(): void
    {
        $cliente = QuoteRequest::make(
            ProductEnum::PARA_TU_AUTO,
            ['cliente' => ClienteMapper::map(InsuranceCustomer::fromArray(self::CUSTOMER))]
        )->toArray()['data']['cliente'];

        $this->assertArrayNotHasKey('paisResidencia', $cliente);
        $this->assertArrayNotHasKey('requiereComprobanteFiscal', $cliente);
        $this->assertArrayNotHasKey('imagenPasaporteUrl', $cliente);
        $this->assertSame('Naco', $cliente['direccion']['sector']);
        $this->assertFalse($cliente['debidaDiligencia']['politicamenteExpuesto']);
    }

    public function testAnEmptyNationalityIsAcceptedAndSentEmpty(): void
    {
        $customer = InsuranceCustomer::fromArray(array_diff_key(self::CUSTOMER, ['nationality' => 1]));

        $this->assertSame('', ClienteMapper::map($customer)['nacionalidad']);
        $this->assertSame([], $customer->missing(ClienteMapper::requiredFields()));
    }

    public function testBooleanFieldsKeepAnExplicitFalse(): void
    {
        $customer = InsuranceCustomer::fromArray(['politically_exposed' => false, 'has_pep_relative' => 'true']);

        $this->assertSame('false', $customer->get(InsuranceCustomerFieldEnum::POLITICALLY_EXPOSED));
        $this->assertTrue($customer->isTrue(InsuranceCustomerFieldEnum::HAS_PEP_RELATIVE));
    }

    public function testMapsTheCanonicalCustomerToUniversalsClienteBlock(): void
    {
        $cliente = ClienteMapper::map(InsuranceCustomer::fromArray(self::CUSTOMER));

        $this->assertSame('Ana', $cliente['nombre']);
        $this->assertSame('Perez', $cliente['apellido']);
        $this->assertSame('F', $cliente['genero']);
        $this->assertSame('C', $cliente['estadoCivil']);
        $this->assertSame('1990-05-17T00:00:00.000Z', $cliente['fechaNacimiento']);
        $this->assertSame('8095550100', $cliente['telefono']);
        $this->assertSame('00100000001', $cliente['numeroDocumento']);
        $this->assertSame('ana@example.com', $cliente['correo']);
    }

    public function testAPoliticallyExposedCustomerMustGiveTheirJobTitle(): void
    {
        $exposed = InsuranceCustomer::fromArray(['politically_exposed' => true] + self::CUSTOMER);

        $this->assertContains(InsuranceCustomerFieldEnum::JOB_TITLE, ClienteMapper::requiredFields($exposed));

        try {
            $exposed->assertHas(ClienteMapper::requiredFields($exposed));
            $this->fail('expected the job title to be required');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('job title', $e->getMessage());
        }

        $this->assertNotContains(
            InsuranceCustomerFieldEnum::JOB_TITLE,
            ClienteMapper::requiredFields(InsuranceCustomer::fromArray(self::CUSTOMER))
        );
    }

    public function testAnUnspecifiedSexCannotBeSentToUniversal(): void
    {
        $this->expectException(ValidationException::class);

        ClienteMapper::map(InsuranceCustomer::fromArray(['sex' => 'unspecified'] + self::CUSTOMER));
    }

    public function testTheCustomerRejectsValuesOutsideTheCanonicalEnums(): void
    {
        $this->expectException(ValidationException::class);

        InsuranceCustomer::fromArray(['sex' => 'M']);
    }

    public function testTheCustomerRejectsAnImpossibleBirthDate(): void
    {
        $this->expectException(ValidationException::class);

        InsuranceCustomer::fromArray(['birth_date' => '1990-02-31']);
    }

    public function testUpdateQuoteReadsTheVehicleBackFromTheQuoteWhenTheCallerSendsNone(): void
    {
        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('getQuote')->once()->with('COT-1')->andReturn(['data' => ['vehiculo' => self::STORED_VEHICLE]]);
        $service->shouldReceive('updateQuote')
            ->once()
            ->withArgs(function (string $quoteNumber, $request): bool {
                $data = $request->toArray()['data'];

                return $quoteNumber === 'COT-1'
                    && $data['vehiculo']['idModelo'] === 77
                    && $data['cliente']['nombre'] === 'Ana'
                    && preg_match('/^kanvas-[0-9a-f]{64}$/', $data['requestId']) === 1;
            })
            ->andReturn(['data' => ['numeroCotizacion' => 'COT-1']]);

        $result = $this->provider($service)->updateQuote(
            'COT-1',
            new InsuranceQuoteRequest(
                product: ProductEnum::PARA_TU_AUTO->value,
                payload: ['cliente' => ['nombre' => 'Ana']]
            )
        );

        $this->assertSame('COT-1', $result->quoteNumber);
    }

    public function testUpdateQuoteDoesNotReadTheQuoteWhenTheCallerSendsTheVehicle(): void
    {
        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldNotReceive('getQuote');
        $service->shouldReceive('updateQuote')->once()->andReturn(['data' => ['numeroCotizacion' => 'COT-1']]);

        $this->provider($service)->updateQuote(
            'COT-1',
            new InsuranceQuoteRequest(
                product: ProductEnum::PARA_TU_AUTO->value,
                payload: ['vehiculo' => self::STORED_VEHICLE, 'cliente' => ['nombre' => 'Ana']]
            )
        );
    }

    public function testTheRequestIdRepeatsForTheSameCustomerAndChangesWhenTheCustomerDoes(): void
    {
        $seen = [];
        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('getQuote')->andReturn(['data' => ['vehiculo' => self::STORED_VEHICLE]]);
        $service->shouldReceive('updateQuote')->andReturnUsing(function (string $quoteNumber, $request) use (&$seen): array {
            $seen[] = $request->toArray()['data']['requestId'];

            return ['data' => ['numeroCotizacion' => $quoteNumber]];
        });
        $provider = $this->provider($service);

        foreach (['Ana', 'Ana', 'Bea'] as $name) {
            $provider->updateQuote(
                'COT-1',
                new InsuranceQuoteRequest(product: ProductEnum::PARA_TU_AUTO->value, payload: ['cliente' => ['nombre' => $name]])
            );
        }

        $this->assertSame($seen[0], $seen[1]);
        $this->assertNotSame($seen[0], $seen[2]);
    }

    public function testUpdateQuoteRefusesToPutAVehicleLessQuote(): void
    {
        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('getQuote')->once()->andReturn(['data' => ['terminos' => []]]);
        $service->shouldNotReceive('updateQuote');

        $this->expectException(ValidationException::class);

        $this->provider($service)->updateQuote(
            'COT-1',
            new InsuranceQuoteRequest(product: ProductEnum::PARA_TU_AUTO->value, payload: ['cliente' => ['nombre' => 'Ana']])
        );
    }
}
