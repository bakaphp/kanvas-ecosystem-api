<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use NeuronAI\Providers\Anthropic\ToolMapper as AnthropicToolMapper;
use NeuronAI\Providers\Gemini\ToolMapper as GeminiToolMapper;
use NeuronAI\Providers\OpenAI\ToolMapper as OpenAIToolMapper;
use NeuronAI\Tools\Tool as NeuronTool;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;
use Throwable;

/**
 * Validates the payload the providers actually receive, not the ToolProperty objects we
 * build it from. Every mapper embeds `ToolPropertyInterface::getJsonSchema()` verbatim, so
 * one malformed property poisons the whole request: Gemini 400s the ENTIRE call — all ~90
 * tools, not just the offender — with `parameters.properties[x].items: missing field`
 * (Sentry KANVAS-ECOSYSTEM-606, a raw `ToolProperty(type: PropertyType::ARRAY)`).
 */
final class AgentToolProviderPayloadTest extends TestCase
{
    /**
     * Gemini accepts only this OpenAPI subset. An unlisted keyword is rejected outright,
     * so drift in NeuronAI's schema generation surfaces here rather than in production.
     */
    private const array GEMINI_ALLOWED_KEYWORDS = [
        'type',
        'format',
        'title',
        'description',
        'nullable',
        'enum',
        'items',
        'minItems',
        'maxItems',
        'properties',
        'required',
        'anyOf',
        'propertyOrdering',
    ];

    private const array VALID_TYPES = ['string', 'integer', 'number', 'boolean', 'array', 'object'];

    public function testEveryToolMapsToAValidGeminiFunctionDeclaration(): void
    {
        $declarations = new GeminiToolMapper()->map($this->allNeuronTools())['functionDeclarations'];

        $violations = [];
        foreach ($declarations as $declaration) {
            $violations = [
                ...$violations,
                ...$this->schemaViolations($declaration['name'], $declaration['parameters']),
            ];
        }

        $this->assertSame([], $violations, $this->explain($violations));
    }

    public function testEveryToolMapsToAValidAnthropicToolSchema(): void
    {
        $violations = [];
        foreach (new AnthropicToolMapper()->map($this->allNeuronTools()) as $tool) {
            $violations = [
                ...$violations,
                ...$this->schemaViolations($tool['name'], $tool['input_schema']),
            ];
        }

        $this->assertSame([], $violations, $this->explain($violations));
    }

    public function testEveryToolMapsToAValidOpenAiFunctionSchema(): void
    {
        $violations = [];
        foreach (new OpenAIToolMapper()->map($this->allNeuronTools()) as $tool) {
            $violations = [
                ...$violations,
                ...$this->schemaViolations($tool['function']['name'], $tool['function']['parameters']),
            ];
        }

        $this->assertSame([], $violations, $this->explain($violations));
    }

    /**
     * Pins the exact declaration Gemini rejected, so a "simplification" back to a bare
     * array property fails loudly instead of only showing up as a production 400.
     */
    public function testCreateArCreditMemoLinesDeclaresItsItemSchema(): void
    {
        $declaration = $this->geminiDeclaration('create_ar_credit_memo');
        $lines = $declaration['parameters']['properties']['lines'];

        $this->assertSame('array', $lines['type']);
        $this->assertSame('object', $lines['items']['type']);
        $this->assertSame(
            ['control_account_number', 'amount', 'description'],
            array_keys($lines['items']['properties'])
        );
        $this->assertSame(['control_account_number', 'amount'], $lines['items']['required']);
        $this->assertContains('lines', $declaration['parameters']['required']);
    }

    /**
     * The only check that proves Gemini itself accepts the payload. Opt-in — set
     * GEMINI_API_KEY to run it; it makes one real (cheap) generateContent call.
     */
    public function testGeminiAcceptsTheFullToolPayload(): void
    {
        $key = (string) env('GEMINI_API_KEY', '');

        if ($key === '') {
            $this->markTestSkipped('Set GEMINI_API_KEY to verify the tool payload against the live API.');
        }

        $model = (string) env('GEMINI_MODEL', 'gemini-3.7-flash');

        $response = Http::timeout(60)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}",
            [
                'contents' => [['role' => 'user', 'parts' => [['text' => 'Say OK.']]]],
                'tools' => [new GeminiToolMapper()->map($this->allNeuronTools())],
            ]
        );

        $this->assertTrue(
            $response->successful(),
            'Gemini rejected the tool payload: ' . $response->body()
        );
    }

    /**
     * Every Neuron tool in the tree, including the ones discovery skips because their
     * constructor needs runtime state (DynamicSubAgent, CreateEngagementPageTool, ...) —
     * they ship in real tool payloads, so they have to be validated too. `properties()`
     * never reads constructor state, so bypassing the constructor is safe here.
     *
     * @return list<NeuronTool>
     */
    private function allNeuronTools(): array
    {
        $tools = [];

        foreach ($this->neuronToolClasses() as $class) {
            try {
                $tool = $this->instantiate(new ReflectionClass($class));
                $tool->getProperties();
            } catch (Throwable) {
                continue;
            }

            $tools[] = $tool;
        }

        $this->assertNotEmpty($tools, 'Tool discovery returned nothing — the payload checks would be vacuous.');

        return $tools;
    }

    /**
     * @return list<class-string<NeuronTool>>
     */
    private function neuronToolClasses(): array
    {
        $classes = [];

        foreach (new Finder()->files()->in(base_path('src'))->name('*Tool.php') as $file) {
            $class = $this->fqcnFromFile($file->getPathname());

            if ($class === null || ! class_exists($class) || ! is_subclass_of($class, NeuronTool::class)) {
                continue;
            }

            if (new ReflectionClass($class)->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    private function fqcnFromFile(string $path): ?string
    {
        $contents = (string) file_get_contents($path);

        if (! preg_match('/^\s*namespace\s+([^;{]+)\s*[;{]/m', $contents, $namespace)) {
            return null;
        }

        if (! preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $contents, $class)) {
            return null;
        }

        return trim($namespace[1]) . '\\' . $class[1];
    }

    /**
     * @param ReflectionClass<NeuronTool> $reflection
     */
    private function instantiate(ReflectionClass $reflection): NeuronTool
    {
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
            return $reflection->newInstance();
        }

        // Tool's promoted constructor properties stay uninitialized when the constructor is
        // bypassed, so seed the ones getProperties()/the mappers read. The name is cosmetic
        // here — it only labels violations.
        $tool = $reflection->newInstanceWithoutConstructor();

        foreach (['properties' => [], 'parameters' => [], 'annotations' => []] as $property => $value) {
            $reflection->getProperty($property)->setValue($tool, $value);
        }

        $reflection->getProperty('name')->setValue($tool, Str::snake($reflection->getShortName()));
        $reflection->getProperty('description')->setValue($tool, null);

        return $tool;
    }

    /**
     * @return array<string, mixed>
     */
    private function geminiDeclaration(string $name): array
    {
        foreach (new GeminiToolMapper()->map($this->allNeuronTools())['functionDeclarations'] as $declaration) {
            if ($declaration['name'] === $name) {
                return $declaration;
            }
        }

        $this->fail("No tool named {$name} was discovered.");
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function schemaViolations(string $path, array $schema, bool $isRoot = true): array
    {
        $violations = [];
        $type = $schema['type'] ?? null;

        if (! in_array($type, self::VALID_TYPES, true)) {
            $violations[] = "{$path}: type '" . var_export($type, true) . "' is not a valid JSON-schema type.";
        }

        if ($type === 'array' && ! isset($schema['items'])) {
            $violations[] = "{$path}: array declared without an `items` schema.";
        }

        // A root parameter object with no properties is the legitimate no-argument case;
        // a nested one describes nothing the model can fill in.
        if ($type === 'object' && ! $isRoot && ! isset($schema['properties'])) {
            $violations[] = "{$path}: object declared without `properties`.";
        }

        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, self::GEMINI_ALLOWED_KEYWORDS, true)) {
                $violations[] = "{$path}: keyword `{$keyword}` is outside Gemini's supported OpenAPI subset.";
            }
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($schema['required'] ?? [] as $required) {
            if (! array_key_exists($required, $properties)) {
                $violations[] = "{$path}: `{$required}` is required but not declared in `properties`.";
            }
        }

        foreach ($properties as $name => $child) {
            if (is_array($child)) {
                $violations = [...$violations, ...$this->schemaViolations("{$path}.{$name}", $child, false)];
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $violations = [...$violations, ...$this->schemaViolations("{$path}[]", $schema['items'], false)];
        }

        return $violations;
    }

    /**
     * @param list<string> $violations
     */
    private function explain(array $violations): string
    {
        return 'The mapped tool payload is malformed. Providers validate the whole request, so one bad '
            . "property breaks every tool call in the turn:\n  - " . implode("\n  - ", $violations);
    }
}
