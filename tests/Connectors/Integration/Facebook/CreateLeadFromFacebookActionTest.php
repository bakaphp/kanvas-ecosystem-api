<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Facebook;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Facebook\Actions\CreateLeadFromFacebookAction;
use Kanvas\Connectors\Facebook\Enums\ConfigurationEnum;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use ReflectionMethod;
use Tests\TestCase;

class CreateLeadFromFacebookActionTest extends TestCase
{
    protected CreateLeadFromFacebookAction $action;

    public function setUp(): void
    {
        parent::setUp();

        $webhookCall = new ReceiverWebhookCall();
        $webhookCall->payload = ['entry' => []];
        $this->action = new CreateLeadFromFacebookAction($webhookCall);
    }

    public function testExtractFieldDataWithEnglishFields(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $fieldData = [
            ['name' => 'full_name', 'values' => ['John Doe']],
            ['name' => 'email', 'values' => ['john@example.com']],
            ['name' => 'phone_number', 'values' => ['555-1234']],
        ];

        $result = $this->invokeExtractFieldData($fieldData, $company);

        $this->assertEquals('John Doe', $result['full_name']);
        $this->assertEquals('john@example.com', $result['email']);
        $this->assertEquals('555-1234', $result['phone_number']);
    }

    public function testExtractFieldDataWithSpanishFields(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $fieldData = [
            ['name' => 'nombre_completo', 'values' => ['Victor M. Sanchez']],
            ['name' => 'número_de_teléfono', 'values' => ['787-366-9189']],
            ['name' => 'correo_electrónico', 'values' => ['victor@example.com']],
        ];

        $result = $this->invokeExtractFieldData($fieldData, $company);

        $this->assertEquals('Victor M. Sanchez', $result['full_name']);
        $this->assertEquals('787-366-9189', $result['phone_number']);
        $this->assertEquals('victor@example.com', $result['email']);
    }

    public function testExtractFieldDataWithSpanishQuestionField(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $fieldData = [
            ['name' => 'nombre_completo', 'values' => ['Victor M. Sanchez']],
            ['name' => '¿en_qué_modelo_estás_interesado?', 'values' => ['outlander']],
            ['name' => 'email', 'values' => ['victor@example.com']],
        ];

        $result = $this->invokeExtractFieldData($fieldData, $company);

        $this->assertEquals('Victor M. Sanchez', $result['full_name']);
        $this->assertEquals('victor@example.com', $result['email']);
        $this->assertArrayHasKey('¿en_qué_modelo_estás_interesado?', $result);
        $this->assertEquals('outlander', $result['¿en_qué_modelo_estás_interesado?']);
    }

    public function testExtractFieldDataWithCompanyCustomMapping(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $company->set(ConfigurationEnum::LEAD_FIELD_MAPPING->value, [
            'en_qué_modelo_estás_interesado' => 'interest',
            'modelo' => 'interest',
        ]);

        $fieldData = [
            ['name' => 'nombre_completo', 'values' => ['Victor M. Sanchez']],
            ['name' => '¿en_qué_modelo_estás_interesado?', 'values' => ['outlander']],
            ['name' => 'email', 'values' => ['victor@example.com']],
        ];

        $result = $this->invokeExtractFieldData($fieldData, $company);

        $this->assertEquals('Victor M. Sanchez', $result['full_name']);
        $this->assertEquals('outlander', $result['interest']);
        $this->assertEquals('victor@example.com', $result['email']);

        $company->del(ConfigurationEnum::LEAD_FIELD_MAPPING->value);
    }

    public function testCompanyMappingOverridesDefaults(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $company->set(ConfigurationEnum::LEAD_FIELD_MAPPING->value, [
            'nombre_completo' => 'name',
        ]);

        $fieldData = [
            ['name' => 'nombre_completo', 'values' => ['Victor M. Sanchez']],
        ];

        $result = $this->invokeExtractFieldData($fieldData, $company);

        $this->assertEquals('Victor M. Sanchez', $result['name']);
        $this->assertArrayNotHasKey('full_name', $result);

        $company->del(ConfigurationEnum::LEAD_FIELD_MAPPING->value);
    }

    public function testCompanyMappingIsIsolatedPerCompany(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $company->set(ConfigurationEnum::LEAD_FIELD_MAPPING->value, [
            'en_qué_modelo_estás_interesado' => 'interest',
        ]);

        $otherCompany = Companies::factory()->create();

        $fieldData = [
            ['name' => '¿en_qué_modelo_estás_interesado?', 'values' => ['outlander']],
            ['name' => 'nombre_completo', 'values' => ['Victor Sanchez']],
        ];

        $resultWithMapping = $this->invokeExtractFieldData($fieldData, $company);
        $this->assertEquals('outlander', $resultWithMapping['interest']);
        $this->assertEquals('Victor Sanchez', $resultWithMapping['full_name']);

        $resultWithoutMapping = $this->invokeExtractFieldData($fieldData, $otherCompany);
        $this->assertArrayNotHasKey('interest', $resultWithoutMapping);
        $this->assertArrayHasKey('¿en_qué_modelo_estás_interesado?', $resultWithoutMapping);
        $this->assertEquals('Victor Sanchez', $resultWithoutMapping['full_name']);

        $company->del(ConfigurationEnum::LEAD_FIELD_MAPPING->value);
    }

    public function testNormalizeFieldNameWithAccentVariations(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $fieldData = [
            ['name' => 'numero_de_telefono', 'values' => ['555-0001']],
            ['name' => 'correo_electronico', 'values' => ['test@example.com']],
            ['name' => 'direccion', 'values' => ['123 Main St']],
            ['name' => 'codigo_postal', 'values' => ['10001']],
            ['name' => 'pais', 'values' => ['US']],
        ];

        $result = $this->invokeExtractFieldData($fieldData, $company);

        $this->assertEquals('555-0001', $result['phone_number']);
        $this->assertEquals('test@example.com', $result['email']);
        $this->assertEquals('123 Main St', $result['street_address']);
        $this->assertEquals('10001', $result['zip_code']);
        $this->assertEquals('US', $result['country']);
    }

    /**
     * @param list<array{name: string, values: list<string>}> $fieldData
     * @return array<string, string>
     */
    private function invokeExtractFieldData(array $fieldData, Companies $company): array
    {
        $method = new ReflectionMethod(CreateLeadFromFacebookAction::class, 'extractFieldData');

        return $method->invoke($this->action, $fieldData, $company);
    }
}
