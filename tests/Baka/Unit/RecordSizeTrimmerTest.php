<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Search\RecordSizeTrimmer;
use Tests\TestCase;

class RecordSizeTrimmerTest extends TestCase
{
    public function testEveryStepIsSkippedWhileTheRecordFits(): void
    {
        $record = ['name' => 'small', 'extra' => 'keep me'];

        $trimmed = RecordSizeTrimmer::make($record, 10000)
            ->forget('extra')
            ->limitString('name', 2)
            ->truncateEverything(1)
            ->get();

        $this->assertSame($record, $trimmed);
    }

    public function testStepsStopAsSoonAsTheRecordFits(): void
    {
        $record = ['keep' => 'a', 'heavy' => str_repeat('x', 500), 'also_heavy' => str_repeat('y', 500)];

        $trimmed = RecordSizeTrimmer::make($record, 600)
            ->forget('heavy', 'also_heavy')
            ->get();

        $this->assertArrayNotHasKey('heavy', $trimmed);
        $this->assertArrayHasKey('also_heavy', $trimmed, 'Dropping the first key was enough — the second survives.');
    }

    public function testDropHeaviestEntriesKeepsTheCheapOnes(): void
    {
        $record = [
            'specs' => [
                'gpu' => 'RTX 5070',
                'novel' => str_repeat('z', 2000),
                'color' => 'red',
            ],
        ];

        $trimmed = RecordSizeTrimmer::make($record, 200)
            ->dropHeaviestEntries('specs')
            ->get();

        $this->assertSame(['gpu' => 'RTX 5070', 'color' => 'red'], $trimmed['specs']);
    }

    public function testTruncateStringsKeepsEveryKeyAndTheObjectShape(): void
    {
        $record = ['body' => (object) ['content' => str_repeat('word ', 500), 'model' => 'gemini']];

        $trimmed = RecordSizeTrimmer::make($record, 300)
            ->truncateStrings('body', [200, 50])
            ->get();

        $this->assertIsObject($trimmed['body']);
        $this->assertSame('gemini', $trimmed['body']->model, 'Short values are untouched.');
        $this->assertStringEndsWith('...', $trimmed['body']->content);
    }

    public function testPopUntilFitReportsOnlyWhatItActuallyDropped(): void
    {
        $dropped = null;
        $record = ['rows' => array_fill(0, 50, ['blob' => str_repeat('x', 100)])];

        $trimmed = RecordSizeTrimmer::make($record, 2000)
            ->popUntilFit('rows', function (int $count) use (&$dropped): void {
                $dropped = $count;
            })
            ->get();

        $this->assertNotNull($dropped);
        $this->assertSame(50 - count($trimmed['rows']), $dropped);
        $this->assertNotEmpty($trimmed['rows'], 'Only as many rows as needed are popped.');
    }

    public function testTruncateEverythingIsTheBackstopForUnknownFields(): void
    {
        $record = [
            'relation_serialized_in' => ['notes' => str_repeat('n', 5000)],
            'body' => (object) ['content' => str_repeat('c', 5000)],
        ];

        $trimmed = RecordSizeTrimmer::make($record, 1000)
            ->truncateEverything(500, 200, 100)
            ->get();

        $this->assertLessThanOrEqual(1000, strlen((string) json_encode($trimmed)));
        $this->assertIsObject($trimmed['body'], 'Object fields keep their type.');
    }
}
