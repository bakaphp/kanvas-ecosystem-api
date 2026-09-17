<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Unit\Companies;

use Illuminate\Support\Facades\Blade;
use Kanvas\Companies\CorporateApplications\Actions\RejectCorporateApplicationAction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The corporate flow falls back to shipped template names when an app has not configured its
 * own. A name the code sends but the data file does not carry is a silent "applicant never
 * hears back" — exactly the defect this file exists to keep closed.
 */
final class CorporateApplicationEmailTemplatesTest extends TestCase
{
    private const string DATA_FILE = 'database/data/movipass_corporate_email_templates.json';

    public static function shippedTemplates(): array
    {
        $sample = ['contactName' => 'Juan Pérez', 'corporateLegalName' => 'Empresa SRL'];

        return [
            'welcome' => ['corporate-welcome', $sample + ['inviteUrl' => 'https://example.com/invite/abc']],
            'needs review' => ['corporate-needs-review', $sample + ['reason' => 'RNC must be 9 or 11 digits']],
            'existing account' => ['corporate-existing-account', $sample + ['loginUrl' => 'https://example.com/login']],
            'rejected' => [RejectCorporateApplicationAction::DEFAULT_TEMPLATE, $sample + ['reason' => 'RNC no existe en DGII']],
        ];
    }

    #[DataProvider('shippedTemplates')]
    public function testShippedTemplateExistsAndRenders(string $name, array $data): void
    {
        $templates = $this->templatesByName();

        $this->assertArrayHasKey($name, $templates, "{$name} is sent by the code but missing from " . self::DATA_FILE);

        $html = Blade::render($templates[$name]['template'], $data);

        $this->assertStringContainsString($data['contactName'], $html);

        if (isset($data['reason'])) {
            $this->assertStringContainsString($data['reason'], $html);
        }
    }

    public function testEveryTemplateHasTheColumnsTheImporterWrites(): void
    {
        foreach ($this->templatesByName() as $name => $template) {
            foreach (['title', 'subject', 'template'] as $column) {
                $this->assertNotSame('', trim((string) ($template[$column] ?? '')), "{$name} has an empty {$column}");
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function templatesByName(): array
    {
        $export = json_decode((string) file_get_contents(base_path(self::DATA_FILE)), true, flags: JSON_THROW_ON_ERROR);

        foreach ($export as $section) {
            if (($section['type'] ?? null) === 'table' && $section['name'] === 'email_templates') {
                return collect($section['data'])->keyBy('name')->all();
            }
        }

        $this->fail('No email_templates table in ' . self::DATA_FILE);
    }
}
