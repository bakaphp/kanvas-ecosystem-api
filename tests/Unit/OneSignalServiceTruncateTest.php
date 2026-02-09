<?php

namespace Tests\Unit;

use Baka\Support\Arr;
use PHPUnit\Framework\TestCase;

class OneSignalServiceTruncateTest extends TestCase
{
    public function testSmallDataIsNotTruncated(): void
    {
        $data = [
            'destination_event' => 'NEW_MESSAGE',
            'destination_id' => 123,
            'title' => 'New Like',
        ];

        $result = Arr::truncateToFit($data);

        $this->assertEquals($data, $result);
    }

    public function testLargeDataIsTruncated(): void
    {
        $data = [
            'destination_event' => 'NEW_MESSAGE',
            'destination_id' => 363791,
            'message' => 'New Like from jonaskan104 on your message',
            'metadata' => [
                'ai_nugget' => [
                    'nugget' => str_repeat('A long AI generated text. ', 200),
                    'title' => 'Instagram Feed: Product Aesthetic',
                    'type' => 'text-format',
                ],
                'ai_model' => [
                    'name' => 'Claude 3.7 Sonnet',
                    'value' => 'claude-3-7-sonnet-latest',
                    'kanvas_sku' => 't2t-claude-3-7-sonnet-latest',
                ],
                'channel_uuid' => 'fde86319-8200-4449-ad32-c4c3e4a91911',
                'title' => 'Cohesive Instagram Feed Creation',
            ],
        ];

        $originalSize = strlen(json_encode($data));
        $this->assertGreaterThan(2048, $originalSize, 'Test data should exceed 2048 bytes');

        $result = Arr::truncateToFit($data);

        $resultSize = strlen(json_encode($result));
        $this->assertLessThanOrEqual(2048, $resultSize, 'Truncated data should be under 2048 bytes');

        // Structure is preserved
        $this->assertArrayHasKey('metadata', $result);
        $this->assertArrayHasKey('ai_nugget', $result['metadata']);
        $this->assertArrayHasKey('nugget', $result['metadata']['ai_nugget']);
        $this->assertArrayHasKey('ai_model', $result['metadata']);

        // Short strings are not truncated
        $this->assertEquals('NEW_MESSAGE', $result['destination_event']);
        $this->assertEquals(363791, $result['destination_id']);

        // Long string was truncated and ends with ...
        $this->assertStringEndsWith('...', $result['metadata']['ai_nugget']['nugget']);
    }

    public function testExtremelyLargeDataStillFits(): void
    {
        $data = [
            'field1' => str_repeat('x', 1000),
            'field2' => str_repeat('y', 1000),
            'nested' => [
                'field3' => str_repeat('z', 1000),
                'deep' => [
                    'field4' => str_repeat('w', 1000),
                ],
            ],
        ];

        $result = Arr::truncateToFit($data);

        $resultSize = strlen(json_encode($result));
        $this->assertLessThanOrEqual(2048, $resultSize);
    }

    public function testDataExactlyAtLimitIsNotTruncated(): void
    {
        $data = ['key' => 'val'];

        $result = Arr::truncateToFit($data, 2048);

        $this->assertEquals($data, $result);
    }

    public function testNumericAndBooleanValuesArePreserved(): void
    {
        $data = [
            'id' => 12345,
            'active' => true,
            'score' => 99.5,
            'long_text' => str_repeat('abc ', 600),
        ];

        $result = Arr::truncateToFit($data);

        $this->assertSame(12345, $result['id']);
        $this->assertSame(true, $result['active']);
        $this->assertSame(99.5, $result['score']);
    }

    public function testTruncateStringsDirectly(): void
    {
        $data = [
            'short' => 'hello',
            'long' => str_repeat('a', 300),
            'nested' => [
                'deep' => str_repeat('b', 500),
            ],
        ];

        $result = Arr::truncateStrings($data, 100);

        $this->assertEquals('hello', $result['short']);
        $this->assertEquals(103, mb_strlen($result['long'])); // 100 + "..."
        $this->assertStringEndsWith('...', $result['long']);
        $this->assertEquals(103, mb_strlen($result['nested']['deep']));
    }
}
