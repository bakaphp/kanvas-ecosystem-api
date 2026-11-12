<?php

declare(strict_types=1);

namespace Tests\Scribe\Delivery;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GetSowTemplateTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SaveSowTemplateTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SetDefaultSowTemplateTool;
use Kanvas\Scribe\Delivery\Actions\OpenDocumentEngagementAction;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteSowException;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Sow\Actions\RecordQuoteSowAction;
use Kanvas\Scribe\Quotes\Sow\Actions\SaveCompanySowTemplateAction;
use Kanvas\Scribe\Quotes\Sow\DataTransferObject\StatementOfWork;
use Kanvas\Scribe\Quotes\Sow\Services\CompanySowTemplateService;
use Kanvas\Scribe\Quotes\Sow\Services\QuoteSowPdfService;
use Kanvas\Templates\Models\Templates;

/**
 * A company's SOW layouts are named versions with a switchable default; each SOW freezes the version it was written
 * with, so the PDF and the signing page render the layout the customer was shown.
 */
final class CompanySowTemplateTest extends DocumentDeliveryTestCase
{
    protected function tearDown(): void
    {
        $this->company->del(CompanySowTemplateService::DEFAULT_SETTING);
        parent::tearDown();
    }

    public function test_the_default_version_renders_new_sows_and_the_signing_page(): void
    {
        $this->save('standard', 'LAYOUT-STANDARD', makeDefault: true);

        $quote = $this->draftQuoteWithSow();

        $this->assertSame('standard', StatementOfWork::fromMessage($quote->sowMessage)->template);
        $this->assertStringContainsString('LAYOUT-STANDARD Build the app.', $this->render($quote));

        $engagement = new OpenDocumentEngagementAction(
            document: $quote,
            people: $this->contact,
            user: static::$cachedUser,
            action: DocumentActionEnum::SIGN_SOW,
        )->execute()->engagement;
        $html = $engagement->refresh()->message->getMessage()['data']['html'];
        $this->assertStringContainsString('LAYOUT-STANDARD', $html);
    }

    public function test_switching_the_default_changes_new_sows_and_never_old_ones(): void
    {
        $this->save('standard', 'LAYOUT-STANDARD', makeDefault: true);
        $this->save('retainer', 'LAYOUT-RETAINER');
        $first = $this->draftQuoteWithSow();

        new CompanySowTemplateService($this->kanvasApp, $this->company)->setDefault('retainer');
        $second = $this->draftQuoteWithSow();

        $this->assertStringContainsString('LAYOUT-STANDARD', $this->render($first->refresh()));
        $this->assertStringContainsString('LAYOUT-RETAINER', $this->render($second));
    }

    public function test_a_sow_can_name_its_version_and_an_unknown_one_is_refused(): void
    {
        $this->save('standard', 'LAYOUT-STANDARD', makeDefault: true);
        $this->save('retainer', 'LAYOUT-RETAINER');
        $quote = $this->draftQuoteWithSow();

        $this->recordSow($quote, 'retainer');
        $this->assertStringContainsString('LAYOUT-RETAINER', $this->render($quote->refresh()));

        $this->expectException(InvalidQuoteSowException::class);
        $this->recordSow($quote, 'nonexistent');
    }

    public function test_a_malformed_version_name_is_a_sow_problem_not_a_locked_quote(): void
    {
        $quote = $this->draftQuoteWithSow();

        $this->expectException(InvalidQuoteSowException::class);
        $this->recordSow($quote, '!!!');
    }

    public function test_clearing_the_default_goes_back_to_the_stock_layout(): void
    {
        $this->save('standard', 'LAYOUT-STANDARD', makeDefault: true);
        new CompanySowTemplateService($this->kanvasApp, $this->company)->setDefault(null);

        $quote = $this->draftQuoteWithSow();

        $this->assertNull(StatementOfWork::fromMessage($quote->sowMessage)->template);
        $html = $this->render($quote);
        $this->assertStringNotContainsString('LAYOUT-STANDARD', $html);
        $this->assertStringContainsString('Build the app.', $html);
    }

    public function test_a_broken_or_remote_loading_layout_is_refused_and_nothing_is_saved(): void
    {
        foreach ([
            '<h1>{{ sow.summary </h1>',
            '<h1>{{ sow.summary }}</h1><script>alert(1)</script>',
            '<img src="https://cdn.example.com/logo.png"><h1>{{ sow.summary }}</h1>',
            '<style>@import url("https://fonts.example.com/x.css");</style><h1>{{ sow.summary }}</h1>',
        ] as $html) {
            try {
                new SaveCompanySowTemplateAction(
                    app: $this->kanvasApp,
                    company: $this->company,
                    user: static::$cachedUser,
                    version: 'broken',
                    html: $html,
                )->execute();
                $this->fail('Saved: ' . $html);
            } catch (ValidationException) {
            }
        }

        $this->assertNull(new CompanySowTemplateService($this->kanvasApp, $this->company)->find('broken'));
    }

    public function test_the_tools_save_list_and_switch_only_this_company(): void
    {
        $tools = fn (string $class): object => new $class()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $saved = $tools(SaveSowTemplateTool::class)
            ->__invoke(version: 'Fixed Price', html: '<h1>FIXED {{ sow.summary }}</h1>', make_default: true);
        $this->assertTrue($saved['saved']);
        $this->assertSame('fixed-price', $saved['version']);
        $this->assertTrue($saved['is_default']);

        $read = $tools(GetSowTemplateTool::class)->__invoke();
        $this->assertSame('fixed-price', $read['default_version']);
        $this->assertSame(['fixed-price'], array_column($read['versions'], 'version'));
        $this->assertStringContainsString('FIXED', $read['twig']);
        $this->assertArrayHasKey('sow', $read['variables']);

        $this->assertTrue($tools(SetDefaultSowTemplateTool::class)->__invoke()['is_stock']);
        $this->assertFalse($tools(SetDefaultSowTemplateTool::class)->__invoke(version: 'nope')['switched']);

        $row = Templates::query()->where('name', 'scribe-sow-fixed-price')->firstOrFail();
        $this->assertSame($this->company->getId(), (int) $row->companies_id);
        $this->assertSame($this->kanvasApp->getId(), (int) $row->apps_id);
    }

    private function save(string $version, string $marker, bool $makeDefault = false): void
    {
        new SaveCompanySowTemplateAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            version: $version,
            html: '<html><body><h1>' . $marker . ' {{ sow.summary }}</h1></body></html>',
            makeDefault: $makeDefault,
        )->execute();
    }

    private function recordSow(Quote $quote, string $template): void
    {
        $sow = StatementOfWork::fromMessage($quote->sowMessage);
        $input = [...$sow->toPayload($quote->getId()), 'template' => $template];

        new RecordQuoteSowAction($quote, StatementOfWork::fromInput($input), static::$cachedUser)->execute();
    }

    private function render(Quote $quote): string
    {
        return new QuoteSowPdfService($quote, $quote->sowMessage)->render();
    }
}
