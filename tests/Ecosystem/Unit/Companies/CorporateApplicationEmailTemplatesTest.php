<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Unit\Companies;

use Illuminate\Support\Facades\Blade;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationEmailEnum as Email;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CorporateApplicationEmailTemplatesTest extends TestCase
{
    private const string CORPORATE_FILE = 'database/data/movipass_corporate_email_templates.json';
    private const string PARKING_FILE = 'database/data/movipass_parking_email_templates.json';

    public static function shippedTemplates(): array
    {
        $sample = ['contactName' => 'Juan Pérez', 'corporateLegalName' => 'Empresa SRL'];
        $overdue = $sample + ['applicationTitle' => 'Parqueo Plaza Central', 'applicantName' => 'Juan Pérez', 'hoursOpen' => 30, 'slaHours' => 24, 'status' => 'pending'];

        return [
            'welcome' => [self::CORPORATE_FILE, Email::WELCOME->defaultTemplate(), $sample + ['inviteUrl' => 'https://example.com/invite/abc']],
            'needs review' => [self::CORPORATE_FILE, Email::NEEDS_REVIEW->defaultTemplate(), $sample + ['reason' => 'RNC must be 9 or 11 digits']],
            'existing account' => [self::CORPORATE_FILE, 'corporate-existing-account', $sample + ['loginUrl' => 'https://example.com/login']],
            'rejected' => [self::CORPORATE_FILE, Email::REJECTED->defaultTemplate(), $sample + ['reason' => 'RNC no existe en DGII']],
            'overdue' => [self::CORPORATE_FILE, Email::OVERDUE->defaultTemplate(), $overdue],
            'parking welcome' => [self::PARKING_FILE, 'parking-welcome', $sample + ['inviteUrl' => 'https://example.com/invite/abc']],
            'parking needs review' => [self::PARKING_FILE, 'parking-needs-review', $sample + ['reason' => 'Faltan fotos de la entrada']],
            'parking rejected' => [self::PARKING_FILE, 'parking-rejected', $sample + ['reason' => 'La dirección no coincide con las coordenadas']],
        ];
    }

    #[DataProvider('shippedTemplates')]
    public function testShippedTemplateExistsAndRenders(string $file, string $name, array $data): void
    {
        $templates = $this->templatesByName($file);

        $this->assertArrayHasKey($name, $templates, "{$name} is sent by the code but missing from {$file}");

        $html = Blade::render($templates[$name]['template'], $data);
        $subject = Blade::render($templates[$name]['subject'], $data);

        $this->assertStringContainsString($data['applicantName'] ?? $data['contactName'], $html);
        $this->assertNotSame('', trim($subject));

        if (isset($data['reason'])) {
            $this->assertStringContainsString($data['reason'], $html);
        }
    }

    public function testOverdueSubjectNamesTheApplication(): void
    {
        $template = $this->templatesByName(self::CORPORATE_FILE)[Email::OVERDUE->defaultTemplate()];

        $this->assertSame(
            'Solicitud atrasada: Parqueo Plaza Central',
            Blade::render($template['subject'], ['applicationTitle' => 'Parqueo Plaza Central'])
        );
    }

    public function testEveryTemplateHasTheColumnsTheImporterWrites(): void
    {
        foreach ([self::CORPORATE_FILE, self::PARKING_FILE] as $file) {
            foreach ($this->templatesByName($file) as $name => $template) {
                foreach (['title', 'subject', 'template'] as $column) {
                    $this->assertNotSame('', trim((string) ($template[$column] ?? '')), "{$name} has an empty {$column}");
                }
            }
        }
    }

    private function templatesByName(string $file): array
    {
        $export = json_decode((string) file_get_contents(base_path($file)), true, flags: JSON_THROW_ON_ERROR);

        foreach ($export as $section) {
            if (($section['type'] ?? null) === 'table' && $section['name'] === 'email_templates') {
                return collect($section['data'])->keyBy('name')->all();
            }
        }

        $this->fail("No email_templates table in {$file}");
    }
}
