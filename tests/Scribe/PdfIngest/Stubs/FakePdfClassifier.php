<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest\Stubs;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\PdfIngest\Contracts\PdfClassifierServiceInterface;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use RuntimeException;

/**
 * Test classifier — returns a queued sequence of pre-set verdicts (one per classify() call) so
 * routing-matrix tests can drive different scenarios without touching Gemini.
 */
class FakePdfClassifier implements PdfClassifierServiceInterface
{
    /** @var array<int, PdfClassificationResult|\Throwable> */
    private array $queue = [];
    public ?bool $throwOnNextCall = false;

    public function queue(PdfClassificationResult $result): self
    {
        $this->queue[] = $result;

        return $this;
    }

    public function queueException(\Throwable $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    public function classify(Filesystem $pdf, array $hints = []): PdfClassificationResult
    {
        if ($this->queue === []) {
            throw new RuntimeException(
                'FakePdfClassifier ran out of queued results — queue another with queue($result).'
            );
        }
        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
