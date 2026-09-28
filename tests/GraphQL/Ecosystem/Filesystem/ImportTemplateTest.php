<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Filesystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ImportTemplateTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'inventory'];

    public function testListsTheShippedTemplates(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            query {
                importTemplates {
                    key
                    name
                    required_columns
                    options
                }
            }
        ');

        $templates = $response->json('data.importTemplates');
        $this->assertNotNull($templates, (string) $response->getContent());

        $dealer = collect($templates)->firstWhere('key', 'DEALER_VEHICLE_CSV');
        $this->assertSame('Dealer inventory (vehicle CSV)', $dealer['name']);
        $this->assertContains('VIN', $dealer['required_columns']);
        $this->assertSame('msrp_first', $dealer['options']['price_source']['default']);
    }

    public function testCreatesAMapperFromATemplateWithOptions(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation($options: Mixed) {
                createFilesystemMapperFromTemplate(template: DEALER_VEHICLE_CSV, options: $options) {
                    id
                    name
                }
            }
        ', ['options' => ['price_source' => 'price_first']]);

        $created = $response->json('data.createFilesystemMapperFromTemplate');
        $this->assertNotNull($created, (string) $response->getContent());
        $template = ImportTemplateEnum::DEALER_VEHICLE_CSV->template();
        $this->assertSame(
            $template->mapperName($template->resolveOptions(['price_source' => 'price_first'])),
            $created['name']
        );

        /** @var Users $user */
        $user = auth()->user();
        $mapper = FilesystemMapper::getByIdFromCompanyApp((int) $created['id'], $user->getCurrentCompany(), app(Apps::class));
        $this->assertSame(['$coalesce' => ['Price', 'MSRP']], $mapper->mapping['price']);
    }

    public function testRejectsAnUnknownOptionChoice(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation {
                createFilesystemMapperFromTemplate(template: DEALER_VEHICLE_CSV, options: {price_source: "cheapest"}) {
                    id
                }
            }
        ');

        $this->assertStringContainsString('price_source must be one of', (string) $response->json('errors.0.message'));
    }
}
