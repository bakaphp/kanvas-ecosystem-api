<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Carbon\Carbon;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Kanvas\Intelligence\Agents\Laravel\Tools\Common\CurrentTimeTool as LaravelCurrentTimeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\CurrentTimeTool as NeuronCurrentTimeTool;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class CurrentTimeToolTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testNeuronToolReturnsCurrentTimeInRequestedTimezone(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 8, 14, 32, 0, 'America/New_York'));

        $result = new NeuronCurrentTimeTool()('America/New_York');

        $this->assertSame('America/New_York', $result['timezone']);
        $this->assertSame('2026-06-08', $result['date']);
        $this->assertSame('14:32:00', $result['time']);
        $this->assertSame('Monday', $result['day_of_week']);
        $this->assertStringContainsString('Monday, June 8, 2026', $result['human']);
        $this->assertFalse($result['is_weekend']);
    }

    public function testNeuronToolFallsBackToUtcWhenTimezoneOmittedOrInvalid(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 8, 12, 0, 0, 'UTC'));

        $omitted = new NeuronCurrentTimeTool()();
        $bogus = new NeuronCurrentTimeTool()('Mars/Olympus_Mons');
        $empty = new NeuronCurrentTimeTool()('   ');

        $this->assertSame('UTC', $omitted['timezone']);
        $this->assertSame('UTC', $bogus['timezone']);
        $this->assertSame('UTC', $empty['timezone']);
        $this->assertSame('2026-06-08', $omitted['date']);
    }

    public function testNeuronToolFlagsWeekend(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 7, 10, 0, 0, 'UTC')); // Sunday

        $result = new NeuronCurrentTimeTool()();

        $this->assertSame('Sunday', $result['day_of_week']);
        $this->assertTrue($result['is_weekend']);
    }

    public function testLaravelToolHandleReturnsJsonWithRequestedTimezone(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 8, 14, 32, 0, 'America/New_York'));

        $tool = new LaravelCurrentTimeTool();
        $request = new Request(['timezone' => 'America/New_York']);
        $raw = $tool->handle($request);

        $decoded = json_decode((string) $raw, true);
        $this->assertIsArray($decoded);
        $this->assertSame('America/New_York', $decoded['timezone']);
        $this->assertSame('2026-06-08', $decoded['date']);
        $this->assertSame('Monday', $decoded['day_of_week']);
        $this->assertFalse($decoded['is_weekend']);
    }

    public function testLaravelToolHandleFallsBackToUtcWhenTimezoneInvalid(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 8, 12, 0, 0, 'UTC'));

        $tool = new LaravelCurrentTimeTool();
        $request = new Request(['timezone' => 'Mars/Olympus_Mons']);
        $decoded = json_decode((string) $tool->handle($request), true);

        $this->assertSame('UTC', $decoded['timezone']);
    }

    public function testLaravelToolSchemaDeclaresOptionalTimezoneString(): void
    {
        $tool = new LaravelCurrentTimeTool();
        $schema = $tool->schema(new JsonSchemaTypeFactory());

        $this->assertArrayHasKey('timezone', $schema);
        $array = $schema['timezone']->toArray();
        $this->assertSame('string', $array['type']);
        $this->assertStringContainsString('IANA timezone', $array['description']);
    }
}
