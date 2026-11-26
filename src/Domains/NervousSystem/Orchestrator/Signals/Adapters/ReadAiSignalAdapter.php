<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Signals\Adapters;

use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Http\Request;
use Kanvas\NervousSystem\Orchestrator\Signals\Contracts\SignalSourceAdapter;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceSettingEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Override;

/**
 * Read.ai webhook → InboundSignal (kind = TRANSCRIPT). Field names verified against a real Read.ai
 * sample payload: the meeting fields sit at the payload root — `trigger` ("meeting_end"), `session_id`,
 * `title`, `start_time` (ISO-8601), `participants[]{name,email}`, `owner{name,email}`, `summary`,
 * `topics[]{text}`, `action_items[]{text}`, `key_questions[]{text}`,
 * `transcript.speaker_blocks[]{speaker{name},words}`. Some
 * relays (Zapier/n8n) nest everything under `data`, so we accept either. Extraction stays defensive —
 * an unexpected shape degrades to empty rather than throwing.
 *
 * A workspace webhook can also fire at `meeting_start`, which carries no transcript, summary or
 * participants; it parses to empty content so the job ignores it.
 *
 * Signature (Read.ai "Getting Started with Webhooks"): `X-Read-Signature` is the lowercase hex
 * HMAC-SHA256 of the raw body, keyed with the webhook's signing key base64-decoded. Only webhooks
 * created after 2026-03-17 are signed.
 */
final class ReadAiSignalAdapter implements SignalSourceAdapter
{
    public const string SIGNATURE_HEADER = 'X-Read-Signature';

    private const string MEETING_START = 'meeting_start';

    #[Override]
    public function parse(array $payload): InboundSignal
    {
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
        $trigger = (string) ($data['trigger'] ?? '');

        return new InboundSignal(
            source: SignalSourceEnum::READ_AI,
            kind: ProjectIngestTypeEnum::TRANSCRIPT,
            externalId: (string) ($data['session_id'] ?? ''),
            title: trim((string) ($data['title'] ?? '')) ?: 'Untitled meeting',
            content: $trigger === self::MEETING_START ? '' : $this->transcript($data),
            occurredAt: isset($data['start_time']) ? (string) $data['start_time'] : null,
            actors: $this->actors($data),
            metadata: [
                'trigger' => $trigger,
                'summary' => trim((string) ($data['summary'] ?? '')),
                'report_url' => trim((string) ($data['report_url'] ?? '')),
                'topics' => $this->textList($data['topics'] ?? []),
                'action_items' => $this->textList($data['action_items'] ?? []),
                'key_questions' => $this->textList($data['key_questions'] ?? []),
                'chapters' => $this->chapters($data['chapter_summaries'] ?? []),
            ],
        );
    }

    /**
     * A company with no signing key yet is accepted, and its events record `signed: false`; one with
     * a key gets nothing through without a matching signature.
     */
    #[Override]
    public function authenticate(Request $request, CompanyInterface $company): bool
    {
        $key = $this->signingKey($company);
        if ($key === null) {
            return true;
        }

        $secret = base64_decode($key, true);
        $signature = strtolower(trim((string) $request->headers->get(self::SIGNATURE_HEADER, '')));

        if ($secret === false || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    #[Override]
    public function isSigned(CompanyInterface $company): bool
    {
        return $this->signingKey($company) !== null;
    }

    private function signingKey(CompanyInterface $company): ?string
    {
        return Str::trimToNull((string) $company->get(SignalSourceSettingEnum::READ_AI_SIGNING_KEY->value));
    }

    /**
     * @return list<string> "Title: description" per chapter
     */
    private function chapters(mixed $chapters): array
    {
        if (! is_array($chapters)) {
            return [];
        }

        $out = [];
        foreach ($chapters as $chapter) {
            if (! is_array($chapter)) {
                continue;
            }

            $line = trim(trim((string) ($chapter['title'] ?? '')) . ': ' . trim((string) ($chapter['description'] ?? '')), ': ');
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * Meeting attendees for the deterministic member-match. Read.ai sends the `owner` as a SEPARATE
     * object (not always inside `participants`), so fold it in — the owner's email is a strong routing
     * signal. De-duplicated by email.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{name: string, email: ?string}>
     */
    private function actors(array $data): array
    {
        $raw = is_array($data['participants'] ?? null) ? $data['participants'] : [];
        if (is_array($data['owner'] ?? null)) {
            array_unshift($raw, $data['owner']);
        }

        $actors = [];
        $seen = [];
        foreach ($raw as $participant) {
            if (! is_array($participant)) {
                continue;
            }

            $email = trim((string) ($participant['email'] ?? ''));
            $key = strtolower($email);
            if ($email !== '' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $actors[] = [
                'name' => trim((string) ($participant['name'] ?? '')),
                'email' => $email !== '' ? $email : null,
            ];
        }

        return $actors;
    }

    /**
     * Read.ai items come as `{text: "..."}` objects or plain strings — normalize both to strings.
     *
     * @return list<string>
     */
    private function textList(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            $text = trim(is_array($item) ? (string) ($item['text'] ?? '') : (string) $item);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * Prefer the full speaker-block transcript; fall back to the summary when only that is present.
     *
     * @param array<string, mixed> $data
     */
    private function transcript(array $data): string
    {
        $blocks = $data['transcript']['speaker_blocks'] ?? null;
        if (is_array($blocks)) {
            $lines = [];
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                // Read.ai normally sends `speaker` as {name}, but some payloads send a bare string —
                // guard both so a string doesn't fatal on the ['name'] offset.
                $speakerRaw = $block['speaker'] ?? '';
                $speaker = is_array($speakerRaw)
                    ? trim((string) ($speakerRaw['name'] ?? ''))
                    : trim((string) $speakerRaw);
                $words = trim((string) ($block['words'] ?? ''));
                if ($words === '') {
                    continue;
                }
                $lines[] = $speaker !== '' ? "{$speaker}: {$words}" : $words;
            }

            if ($lines !== []) {
                return implode("\n", $lines);
            }
        }

        return trim((string) ($data['summary'] ?? ''));
    }
}
