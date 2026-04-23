<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Kanvas\Imports\Actions\SpoolImporterPayloadAction;
use RuntimeException;
use Tests\TestCaseUnit;

class SpoolImporterPayloadActionTest extends TestCaseUnit
{
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testWriteJsonlProducesOneJsonObjectPerLine(): void
    {
        $rows = [
            ['name' => 'Product A', 'sku' => 'SKU-1'],
            ['name' => 'Product B', 'sku' => 'SKU-2'],
            ['name' => 'Product C', 'sku' => 'SKU-3'],
        ];
        $path = $this->tempPath();

        SpoolImporterPayloadAction::writeJsonl($rows, $path);

        $lines = $this->readLines($path);
        $this->assertCount(3, $lines);
        $this->assertSame($rows[0], json_decode($lines[0], true));
        $this->assertSame($rows[1], json_decode($lines[1], true));
        $this->assertSame($rows[2], json_decode($lines[2], true));
    }

    public function testWriteJsonlPreservesNestedStructures(): void
    {
        $rows = [
            [
                'name' => 'Bundle',
                'sku' => 'BUNDLE-1',
                'attributes' => [
                    ['name' => 'color', 'value' => 'red'],
                    ['name' => 'size', 'value' => 'large'],
                ],
                'variants' => [
                    ['sku' => 'BUNDLE-1-A', 'price' => 10.5],
                    ['sku' => 'BUNDLE-1-B', 'price' => 20.99],
                ],
            ],
        ];
        $path = $this->tempPath();

        SpoolImporterPayloadAction::writeJsonl($rows, $path);

        $lines = $this->readLines($path);
        $this->assertCount(1, $lines);
        $this->assertSame($rows[0], json_decode($lines[0], true));
    }

    public function testWriteJsonlHandlesEmptyArrayAsEmptyFile(): void
    {
        $path = $this->tempPath();

        SpoolImporterPayloadAction::writeJsonl([], $path);

        $this->assertFileExists($path);
        $this->assertSame('', file_get_contents($path));
    }

    public function testWriteJsonlOutputIsRoundTrippable(): void
    {
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = [
                'name' => 'Product ' . $i,
                'sku' => 'SKU-' . $i,
                'price' => $i + 0.99,
                'tags' => ['tag-' . $i, 'common'],
            ];
        }
        $path = $this->tempPath();

        SpoolImporterPayloadAction::writeJsonl($rows, $path);

        // Read back row-by-row the way the worker will (streaming) and confirm
        // every original row is recovered intact.
        $reconstructed = [];
        $handle = fopen($path, 'r');
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $reconstructed[] = json_decode($line, true);
        }
        fclose($handle);

        $this->assertSame($rows, $reconstructed);
    }

    public function testWriteJsonlThrowsWhenFileCannotBeOpened(): void
    {
        $unwritablePath = '/nonexistent-directory-' . uniqid() . '/file.jsonl';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not open file for JSONL spool');

        SpoolImporterPayloadAction::writeJsonl([['x' => 1]], $unwritablePath);
    }

    public function testWriteJsonlNeverMaterializesFullPayloadAsSingleJsonString(): void
    {
        // Sanity check: even with a payload that would produce a multi-MB
        // single JSON string, the writer completes by streaming row-by-row.
        // The OOM trap was `json_encode($entirePayload)` in one shot.
        $rows = [];
        for ($i = 0; $i < 5000; $i++) {
            $rows[] = [
                'name' => 'Product ' . $i,
                'description' => str_repeat('lorem ipsum ', 50),
            ];
        }
        $path = $this->tempPath();

        SpoolImporterPayloadAction::writeJsonl($rows, $path);

        $lineCount = 0;
        $handle = fopen($path, 'r');
        while (fgets($handle) !== false) {
            $lineCount++;
        }
        fclose($handle);

        $this->assertSame(5000, $lineCount);
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/spool-test-' . uniqid() . '.jsonl';
        $this->tempPaths[] = $path;

        return $path;
    }

    private function readLines(string $path): array
    {
        $contents = file_get_contents($path);
        $this->assertStringEndsWith("\n", $contents, 'Each JSONL row must end with a newline');

        return array_values(array_filter(explode("\n", $contents), fn ($l) => $l !== ''));
    }
}
