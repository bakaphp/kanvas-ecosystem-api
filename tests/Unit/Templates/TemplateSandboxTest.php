<?php

declare(strict_types=1);

namespace Tests\Unit\Templates;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Templates\Support\TemplateSandbox;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseUnit;

final class TemplateSandboxTest extends TestCaseUnit
{
    private const array CONTEXT = [
        'company_name' => "O'Brien Motors",
        'people' => ['name' => 'Ana'],
        'lead' => [
            'people' => ['firstname' => 'Ana'],
            'lead_status' => ['name' => 'hot'],
        ],
        'vehicle_interest' => ['make' => 'Kia'],
        'conversation_history' => [['role' => 'user', 'text' => 'hi/there ñ']],
    ];

    /**
     * @return array<string, array{string, string}>
     */
    public static function legacyBladeProvider(): array
    {
        return [
            'variable' => ['Hi {{$company_name}}', "Hi O'Brien Motors"],
            'array key' => ['{{$people["name"]}}', 'Ana'],
            'property chain with default' => ["{{ \$lead->people->firstname ?? 'prospect' }}", 'Ana'],
            'nullsafe camelCase relation' => ["{{ \$lead?->leadStatus['name'] }}", 'hot'],
            'missing with double-quoted default' => ['{{ $missing->x ?? "there" }}', 'there'],
            'missing without default' => ['[{{ $missing }}]', '[]'],
            'array prints as json' => ['{{$vehicle_interest}}', '{"make":"Kia"}'],
            'blade comment dropped' => ['a{{-- note --}}b', 'ab'],
            'json_encode with flags' => [
                '{!! json_encode($conversation_history, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}',
                '[{"role":"user","text":"hi/there ñ"}]',
            ],
            'native twig' => ["{% if lead.lead_status.name == 'hot' %}HOT {{ people.name }}{% endif %}", 'HOT Ana'],
        ];
    }

    #[DataProvider('legacyBladeProvider')]
    public function testRendersStoredBladeExpressions(string $template, string $expected): void
    {
        $this->assertSame($expected, TemplateSandbox::render($template, self::CONTEXT));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function codePayloadProvider(): array
    {
        return [
            'blade php block' => ["@php file_put_contents('%s', 'x') @endphp"],
            'blade raw echo call' => ["{!! file_put_contents('%s', 'x') !!}"],
            'blade echo call' => ["{{ file_put_contents('%s', 'x') }}"],
            'twig map callable' => ["{{ ['%s']|map('touch')|join }}"],
            'twig filter callable' => ["{{ ['%s']|filter('touch')|join }}"],
            'twig sort callable' => ["{{ ['%s', 'b']|sort('touch')|join }}"],
            'twig include' => ["{{ include('%s') }}"],
            'twig constant' => ["{{ constant('PHP_VERSION') }}%s"],
        ];
    }

    #[DataProvider('codePayloadProvider')]
    public function testCodePayloadsStayInert(string $payload): void
    {
        $marker = sys_get_temp_dir() . '/template-sandbox-' . uniqid();
        $template = sprintf($payload, $marker);

        $rendered = TemplateSandbox::render($template, self::CONTEXT);

        $this->assertFileDoesNotExist($marker);
        $this->assertStringNotContainsString(PHP_VERSION, $rendered);
    }

    public function testNeverCallsAMethodOnAModelInContext(): void
    {
        $model = new class () extends Model {
            public static bool $called = false;

            public function wipe(): void
            {
                self::$called = true;
            }
        };
        $model->setRawAttributes(['id' => 42]);

        $rendered = TemplateSandbox::render('{{ $lead->wipe }}|{{ $lead->id }}|{{ lead.wipe }}', ['lead' => $model]);

        $this->assertFalse($model::$called);
        $this->assertSame('|42|', $rendered);
    }

    public function testLoopsAreNotAllowed(): void
    {
        $template = '{% for i in 1..3 %}x{% endfor %}';

        $this->assertSame($template, TemplateSandbox::render($template));
    }

    public function testIfBranchesRender(): void
    {
        $template = '{% if day > 3 %}late{% elseif day > 1 %}mid{% else %}early{% endif %}';

        $this->assertSame('mid', TemplateSandbox::render($template, ['day' => 2]));
        $this->assertSame('early', TemplateSandbox::render($template, ['day' => 0]));
    }
}
