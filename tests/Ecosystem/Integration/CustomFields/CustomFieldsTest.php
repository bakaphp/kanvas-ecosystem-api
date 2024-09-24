<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\CustomFields;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Templates\Models\Templates;
use Tests\TestCase;

final class CustomFieldsTest extends TestCase
{
    public function createTemplate(): Templates
    {
        return Templates::firstOrCreate([
            'users_id' => 1,
            'companies_id' => 1,
            'apps_id' => 1,
            'name' => 'test',
        ], [
            'users_id' => 1,
            'companies_id' => 1,
            'apps_id' => 1,
            'name' => 'test',
            'template' => 'test',
        ]);
    }

    public function testSaveCustomField()
    {
        $template = $this->createTemplate();
        $value = fake()->name;
        $template->set('test', $value);

        $this->assertEquals($value, $template->get('test'));
    }

    public function testSaveArrayCustomField()
    {
        $template = $this->createTemplate();
        $value = [fake()->name, fake()->name, fake()->name];
        $template->set('test', $value);

        $this->assertEquals($value, $template->get('test'));
    }

    public function testUpdateCustomField()
    {
        $template = $this->createTemplate();

        $value = fake()->name;
        $template->set('test', $value);

        $this->assertEquals($value, $template->get('test'));
        $value = fake()->name;
        $template->set('test', $value);
        $this->assertEquals($value, $template->get('test'));
    }

    public function testDeleteCustomField()
    {
        $template = $this->createTemplate();

        $value = fake()->name;
        $template->set('test', $value);

        $template->del('test');
        $this->assertEmpty($template->get('test'));
    }

    public function testMassAssignment()
    {
        $template = $this->createTemplate();
        $template->deleteAllCustomFields();

        $template->setCustomFields([
            'test_1' => fake()->name,
            'test_2' => fake()->name,
            'test_3' => fake()->name,
        ]);

        $template->saveCustomFields();

        $this->assertCount(3, $template->getAll());
    }

    public function testDontAllowModelProperty()
    {
        $template = $this->createTemplate();
        $template->deleteAllCustomFields();

        $template->setCustomFields([
            'test_1' => fake()->name,
            'test_2' => fake()->name,
            'test_3' => fake()->name,
            'test_4' => fake()->name,
            'name' => fake()->name,
            'template' => fake()->name,
        ]);

        $template->saveCustomFields();

        $this->assertCount(4, $template->getAll());
    }

    public function testDeleteAll()
    {
        $template = $this->createTemplate();

        $template->setCustomFields([
            'test_1' => fake()->name,
            'test_2' => fake()->name,
            'test_3' => fake()->name,
        ]);

        $template->saveCustomFields();
        $template->deleteAllCustomFields();

        $this->assertCount(0, $template->getAll());
    }

    public function testEntityCustomFields()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $people->set('test', 'test');

        $this->assertEquals('test', $people->get('test'));

        $people->set('array', ['test', 'test', 'test']);
        $this->assertEquals(['test', 'test', 'test'], $people->get('array'));

        $people->set('json', json_encode(['test', 'test', 'test']));
        $this->assertEquals(['test', 'test', 'test'], $people->get('json'));
    }

    public function testEntityGetAllCustomFields()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $people->set('test', 'test');
        $people->set('array', ['test', 'test', 'test']);
        $people->set('json', json_encode(['test', 'test', 'test']));

        // Ensure that all fields are retrieved correctly
        $customFields = $people->getAll();
        $this->assertCount(3, $customFields);

        $this->assertEquals('test', $customFields['test']);
        $this->assertEquals(['test', 'test', 'test'], $customFields['array']);
        $this->assertEquals(['test', 'test', 'test'], $customFields['json']);
    }
}
