<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Sow\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Sow\Services\CompanySowTemplateService;
use Kanvas\Scribe\Quotes\Sow\Services\QuoteSowPdfService;
use Kanvas\Scribe\Quotes\Sow\Support\SampleSowContext;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Models\Templates;
use Kanvas\Templates\Support\TemplateSandbox;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Creates or updates one of a company's SOW layout versions. It is rendered before it is stored, against the
 * company's latest SOW (or a sample when it has none), so a template that breaks never reaches a customer.
 *
 * The same HTML is what the `sign-sow` engagement page shows in a sandboxed iframe that blocks scripts and remote
 * assets, so a layout that loads anything from the network is refused: it would print fine and arrive broken.
 */
class SaveCompanySowTemplateAction
{
    private const array REMOTE_ASSET_PATTERNS = [
        '/<\s*script\b/i' => 'scripts',
        '/@import\b/i' => '@import',
        '/<\s*(img|link|iframe|video|audio|source|embed|object)\b[^>]*\b(src|href)\s*=\s*["\']?\s*(https?:)?\/\//i' => 'remote images, stylesheets or frames',
        '/url\(\s*["\']?\s*(https?:)?\/\//i' => 'remote url() in CSS',
    ];

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly string $version,
        private readonly string $html,
        private readonly bool $makeDefault = false,
    ) {
    }

    public function execute(): Templates
    {
        $version = CompanySowTemplateService::normalizeVersion($this->version);
        $html = trim($this->html);

        if ($html === '') {
            throw new ValidationException('The SOW template is empty.');
        }

        $this->assertIframeSafe($html);
        $this->assertRenders($html);

        $templates = new CompanySowTemplateService($this->app, $this->company);
        $existing = $templates->find($version);

        if ($existing !== null) {
            $existing->template = $html;
            $existing->saveOrFail();
            $template = $existing;
        } else {
            $template = new CreateTemplateAction(
                new TemplateInput(
                    app: $this->app,
                    name: CompanySowTemplateService::templateName($version),
                    template: $html,
                    title: 'Statement of Work',
                    company: $this->company,
                    user: $this->user,
                )
            )->execute();
        }

        if ($this->makeDefault) {
            $templates->setDefault($version);
        }

        return $template;
    }

    private function assertIframeSafe(string $html): void
    {
        $found = [];

        foreach (self::REMOTE_ASSET_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $html) === 1) {
                $found[] = $label;
            }
        }

        if ($found !== []) {
            throw new ValidationException(
                'The SOW page shows this layout in a sandbox that blocks ' . implode(', ', $found) . '. Remove them: '
                . 'the logo is {{ issuer.logo }} (already embedded), styles go in a <style> block, fonts are a system '
                . 'stack such as Arial, Helvetica, sans-serif.'
            );
        }
    }

    private function assertRenders(string $html): void
    {
        try {
            TemplateSandbox::renderHtml($html, $this->context());
        } catch (Throwable $e) {
            throw new ValidationException('The SOW template does not render, nothing was saved: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        /** @var Quote|null $quote */
        $quote = Quote::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereNotNull('sow_message_id')
            ->latest('id')
            ->first();

        $message = $quote?->sowMessage;

        return $quote !== null && $message !== null
            ? new QuoteSowPdfService($quote, $message)->viewData()
            : SampleSowContext::get();
    }
}
