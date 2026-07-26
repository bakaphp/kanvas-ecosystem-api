<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Signals\Enums;

use Kanvas\NervousSystem\Orchestrator\Signals\Adapters\PlainTranscriptSignalAdapter;
use Kanvas\NervousSystem\Orchestrator\Signals\Adapters\ReadAiSignalAdapter;
use Kanvas\NervousSystem\Orchestrator\Signals\Adapters\WebVttSignalAdapter;
use Kanvas\NervousSystem\Orchestrator\Signals\Contracts\SignalSourceAdapter;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;

/**
 * Where an inbound orchestrator signal came from — a structured provider (Read.ai), a WebVTT transcript
 * recorder, a plain timestamped transcript export, an email connector, a CRM webhook, etc. Each case
 * owns the adapter that normalizes its raw payload into an InboundSignal, so adding a source is: a new
 * case here + its adapter class + one match arm. Distinct from the signal's *kind*
 * (ProjectIngestTypeEnum) — one source can carry different kinds.
 */
enum SignalSourceEnum: string
{
    case READ_AI = 'read_ai';
    case WEBVTT = 'webvtt';
    case PLAIN_TRANSCRIPT = 'plain_transcript';
    case TRANSCRIPT = 'transcript';

    public function adapter(): SignalSourceAdapter
    {
        return match ($this) {
            self::READ_AI => new ReadAiSignalAdapter(),
            self::WEBVTT => new WebVttSignalAdapter(),
            self::PLAIN_TRANSCRIPT,
            self::TRANSCRIPT => new PlainTranscriptSignalAdapter(),
        };
    }

    /**
     * Ingest is single-source per receiver, but a shared endpoint sometimes receives a format that
     * doesn't match its configured source (e.g. a WebVTT body on a `read_ai` receiver). Adapters are
     * pure parsers with no side effects, so before giving up we probe them: try the preferred source
     * first (respecting config), then every other adapter, and return the first that actually extracts
     * content. Each adapter yields content only for its own format, so mismatches fall through to empty.
     * Returns null when no adapter can parse the payload. The returned signal's `source` reflects the
     * adapter that matched, not necessarily the configured one.
     *
     * @param array<string, mixed> $payload
     */
    public static function parseWithFallback(array $payload, ?self $preferred = null): ?InboundSignal
    {
        $ordered = $preferred !== null
            ? [$preferred, ...array_filter(self::cases(), static fn (self $case): bool => $case !== $preferred)]
            : self::cases();

        $tried = [];
        foreach ($ordered as $source) {
            $adapter = $source->adapter();
            if (isset($tried[$adapter::class])) {
                continue;
            }
            $tried[$adapter::class] = true;

            $signal = $adapter->parse($payload);
            if (trim($signal->content) !== '') {
                return $signal;
            }
        }

        return null;
    }
}
