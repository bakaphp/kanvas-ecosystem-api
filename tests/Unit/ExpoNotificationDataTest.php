<?php

namespace Tests\Unit;

use NotificationChannels\Expo\ExpoMessage;
use PHPUnit\Framework\TestCase;

class ExpoNotificationDataTest extends TestCase
{
    public function testEmptyDataProducesArrayInJson(): void
    {
        // This demonstrates the bug: passing empty array to data() produces "[]" (JSON array)
        $message = ExpoMessage::create('Title')
            ->body('Body')
            ->data([]);

        $array = $message->toArray();

        $this->assertArrayHasKey('data', $array);
        $this->assertEquals('[]', $array['data'], 'Empty array encodes as JSON array "[]"');
    }

    public function testSkippingDataWhenEmptyExcludesItFromPayload(): void
    {
        // This is the fix: when filtered data is empty, don't call data() at all
        $filtered = [];

        $message = ExpoMessage::create('Title')
            ->body('Body')
            ->when(! empty($filtered), fn (ExpoMessage $msg) => $msg->data($filtered))
            ->priority('high')
            ->playSound();

        $array = $message->toArray();

        $this->assertArrayNotHasKey('data', $array, 'Empty data should be excluded from payload');
        $this->assertEquals('Title', $array['title']);
        $this->assertEquals('Body', $array['body']);
    }

    public function testNonEmptyDataIsIncludedAsJsonObject(): void
    {
        $filtered = [
            'destination_event' => 'NEW_MESSAGE',
            'destination_id' => 123,
            'title' => 'New Like',
        ];

        $message = ExpoMessage::create('Title')
            ->body('Body')
            ->when(! empty($filtered), fn (ExpoMessage $msg) => $msg->data($filtered))
            ->priority('high')
            ->playSound();

        $array = $message->toArray();

        $this->assertArrayHasKey('data', $array);

        // Verify data is a JSON object string (starts with {), not array (starts with [)
        $this->assertStringStartsWith('{', $array['data']);
        $this->assertStringEndsWith('}', $array['data']);

        $decoded = json_decode($array['data'], true);
        $this->assertEquals('NEW_MESSAGE', $decoded['destination_event']);
        $this->assertEquals(123, $decoded['destination_id']);
    }

    public function testScalarFilteringProducesValidObjectKeys(): void
    {
        // Simulates what NotificationExpoTrait does: filter to only scalar values
        $additionalData = [
            'destination_event' => 'NEW_MESSAGE',
            'destination_id' => 363791,
            'message' => 'New Like from user',
            'metadata' => ['nested' => 'object'],  // non-scalar, will be filtered out
            'fromUser' => (object) ['id' => 1],     // non-scalar, will be filtered out
        ];

        $filtered = [];
        foreach ($additionalData as $key => $value) {
            if (is_scalar($value)) {
                $filtered[$key] = $value;
            }
        }

        $this->assertNotEmpty($filtered);

        $message = ExpoMessage::create('Title')
            ->body('Body')
            ->when(! empty($filtered), fn (ExpoMessage $msg) => $msg->data($filtered));

        $decoded = json_decode($message->toArray()['data'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('destination_event', $decoded);
        $this->assertArrayNotHasKey('metadata', $decoded);
    }

    public function testAllNonScalarDataResultsInNoDataField(): void
    {
        // When ALL values are non-scalar, filtered is empty → data should be excluded
        $additionalData = [
            'metadata' => ['nested' => 'object'],
            'company' => (object) ['id' => 1],
        ];

        $filtered = [];
        foreach ($additionalData as $key => $value) {
            if (is_scalar($value)) {
                $filtered[$key] = $value;
            }
        }

        $this->assertEmpty($filtered);

        $message = ExpoMessage::create('Title')
            ->body('Body')
            ->when(! empty($filtered), fn (ExpoMessage $msg) => $msg->data($filtered))
            ->priority('high');

        $array = $message->toArray();

        $this->assertArrayNotHasKey('data', $array, 'No data field when all values are non-scalar');
    }
}
