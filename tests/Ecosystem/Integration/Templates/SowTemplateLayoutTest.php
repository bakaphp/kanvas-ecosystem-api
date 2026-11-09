<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Templates;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Templates\Support\TemplateSandbox;
use Tests\TestCase;

final class SowTemplateLayoutTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    public function testAuthoredLineBreaksSurviveWithoutRenderingHtmlFromTheDocument(): void
    {
        $context = json_decode(file_get_contents(base_path('tests/fixtures/templates/scribe-sow.json')), true, flags: JSON_THROW_ON_ERROR);
        $paths = [
            'issuer.address_lines.0',
            'customer.address_lines.0',
            'sow.summary',
            'sow.scope.0',
            'sow.out_of_scope.0',
            'sow.assumptions.0',
            'sow.deliverables.0.name',
            'sow.deliverables.0.acceptance_criteria',
            'sow.change_process',
            'lines.0.description',
            'payment_rows.0.label',
            'terms',
        ];

        foreach ($paths as $path) {
            data_set($context, $path, "$path <em>first</em>\nsecond\n\nparagraph");
        }

        $html = TemplateSandbox::renderHtml(file_get_contents(resource_path('views/pdf/scribe-sow.twig')), $context);
        $document = new DOMDocument();
        $document->loadHTML($html);
        $multiline = [];
        foreach (new DOMXPath($document)->query('//*[contains(concat(" ", normalize-space(@class), " "), " multiline ")]') as $node) {
            $multiline[] = $node->textContent;
        }

        foreach ($paths as $path) {
            $this->assertContains("$path <em>first</em>\nsecond\n\nparagraph", $multiline);
        }
        $this->assertStringContainsString('white-space: pre-line', $html);
        $this->assertStringNotContainsString('&lt;br', $html);
        $this->assertStringNotContainsString('<em>first</em>', $html);
        $this->assertStringContainsString('Jun 19, 2019', $html);
        $this->assertStringContainsString('95,950.00', $html);
    }

    public function testMigrationUpdatesOnlyUnmodifiedActiveTwigSowCopies(): void
    {
        $original = file_get_contents(base_path('tests/fixtures/templates/scribe-sow-v1.twig'));
        $current = file_get_contents(resource_path('views/pdf/scribe-sow.twig'));
        $companyId = random_int(1900000000, 2000000000);
        $rows = [
            ['template' => $original],
            ['template' => $original],
            ['template' => $original . '\n<!-- Custom branding -->'],
            ['template' => $original, 'engine' => 'blade'],
            ['template' => $original, 'is_deleted' => 1],
            ['template' => $original, 'name' => 'another-document'],
        ];
        $ids = [];

        foreach ($rows as $index => $row) {
            $ids[] = DB::table('email_templates')->insertGetId([
                'apps_id' => app(Apps::class)->getId(),
                'companies_id' => $companyId + $index,
                'users_id' => auth()->id(),
                'name' => 'scribe-sow',
                'engine' => 'twig',
                'is_deleted' => 0,
                'created_at' => now(),
                ...$row,
            ]);
        }

        $migration = require database_path('migrations/2026_11_08_120000_refresh_stock_sow_layout.php');
        $migration->up();
        $migration->up();

        foreach ($ids as $index => $id) {
            $this->assertSame($index < 2 ? $current : $rows[$index]['template'], DB::table('email_templates')->where('id', $id)->value('template'));
        }
    }

    public function testLogoMigrationUpdatesStockCopiesAndPreservesCustomTemplates(): void
    {
        $fixtures = ['scribe-sow' => 'scribe-sow-v2.twig', 'scribe-ecf-invoice' => 'scribe-ecf-invoice-v1.twig'];
        $expected = [];

        foreach ($fixtures as $name => $fixture) {
            $original = file_get_contents(base_path('tests/fixtures/templates/' . $fixture));
            $current = file_get_contents(resource_path('views/pdf/' . $name . '.twig'));
            $rows = [
                ['template' => $original],
                ['template' => $original . '\n<!-- Custom branding -->'],
                ['template' => $original, 'engine' => 'blade'],
                ['template' => $original, 'is_deleted' => 1],
                ['template' => $original, 'name' => 'another-document'],
            ];

            foreach ($rows as $index => $row) {
                $id = DB::table('email_templates')->insertGetId([
                    'apps_id' => app(Apps::class)->getId(),
                    'companies_id' => random_int(1900000000, 2000000000),
                    'users_id' => auth()->id(),
                    'name' => $name,
                    'engine' => 'twig',
                    'is_deleted' => 0,
                    'created_at' => now(),
                    ...$row,
                ]);
                $expected[$id] = $index === 0 ? $current : $row['template'];
            }
        }

        $migration = require database_path('migrations/2026_11_09_140000_resize_stock_document_logos.php');
        $migration->up();
        $migration->up();

        foreach ($expected as $id => $template) {
            $this->assertSame($template, DB::table('email_templates')->where('id', $id)->value('template'));
        }
    }
}
