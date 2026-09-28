<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Helpers;

class ChatHelper
{
    public static function extractTextFromResponse(string $response): string
    {
        return self::collapseDuplicatedBody(self::resolveResponseText($response));
    }

    private static function resolveResponseText(string $response): string
    {
        $data = self::extractJsonEnvelope($response);
        if ($data !== null) {
            return self::pickResponseField($data);
        }

        // Malformed/truncated JSON the decoder couldn't parse — pull the response
        // field out by regex (handles `{"response": "text"` cut off mid-stream).
        if (preg_match('/\{.*"response"\s*:\s*"(.*?)"\s*(?:,.*?)?\}/s', $response, $matches)) {
            return str_replace('\n', "\n", $matches[1]); // Handle escaped newlines
        }

        // Last resort: return the original response with markdown formatting removed
        return (string) preg_replace('/```(?:json)?\s*(.*)\s*```/s', '$1', $response);
    }

    /**
     * Collapse a body the model emitted twice back to a single copy.
     *
     * Sales agents intermittently generate the whole reply twice (confirmed by the
     * provider reporting ~2x the output tokens for one logical message), producing
     * `"<reply>\n\n<reply>"`. Selecting one JSON field can't fix that — the doubling is
     * inside the text. We split on blank lines and, when the paragraph list is exactly
     * its own first half repeated, keep one half. Conservative: only an exact
     * paragraph-for-paragraph match collapses, so a normal reply is never truncated.
     */
    private static function collapseDuplicatedBody(string $text): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return $text;
        }

        $split = preg_split('/\n\s*\n/', $trimmed);
        if ($split === false) {
            return $text;
        }

        $paragraphs = array_map('trim', $split);
        $count = count($paragraphs);
        if ($count < 2 || $count % 2 !== 0) {
            return $text;
        }

        $half = intdiv($count, 2);
        for ($i = 0; $i < $half; $i++) {
            if ($paragraphs[$i] === '' || $paragraphs[$i] !== $paragraphs[$i + $half]) {
                return $text;
            }
        }

        return implode("\n\n", array_slice($paragraphs, 0, $half));
    }

    /**
     * Pull the email subject out of the agent's JSON envelope, if it emitted one.
     *
     * Structured-first companion to {@see extractEmailSubjectAndBody()}: an email
     * agent that replies with `{"subject": "...", "response": "<body>"}` gives us a
     * clean subject without parsing a `Subject:` line out of the body. Returns null
     * when the response isn't JSON or carries no subject field — callers fall back
     * to the in-body `Subject:` heuristic.
     */
    public static function extractSubjectFromResponse(string $response): ?string
    {
        $data = self::extractJsonEnvelope($response);
        if ($data === null) {
            return null;
        }

        foreach (['subject', 'email_subject', 'title'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                return trim($data[$key]);
            }
        }

        return null;
    }

    /**
     * Decode an agent response into its JSON envelope array, trying the whole string,
     * a fenced ```json block, then a bare `{...}` anywhere in the text. Returns null
     * when nothing parses as a JSON object.
     *
     * Public because the reply text alone is lossy: {@see pickResponseField()} selects ONE
     * field, so an agent that answers with a whole record (a post, a quote, an enrichment)
     * loses every field but the body. Callers that persist the reply keep the envelope
     * alongside it so a later activity can consume the structure.
     *
     * @return array<array-key, mixed>|null
     */
    public static function extractJsonEnvelope(string $response): ?array
    {
        $candidates = [$response];

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $response, $matches)) {
            $candidates[] = $matches[1];
        }

        // `[` as well as `{`: a list of records is an envelope too, and anchoring on `{` alone left
        // a fenced array unparsed, so the caller published the model's raw JSON as its own body.
        if (preg_match('/[\{\[].*[\}\]]/s', $response, $matches)) {
            $candidates[] = $matches[0];
        }

        foreach ($candidates as $candidate) {
            $data = json_decode(trim($candidate), true);

            if (json_last_error() === JSON_ERROR_NONE && self::isEnvelope($data)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * The single record an envelope describes: itself when it is an object, its first record when the
     * agent answered with several in one turn. Null when a list carries no record at all — prose with
     * a bracketed aside, `Los pasos son [1, 2, 3]`, decodes as a perfectly valid JSON array, and
     * reading that as structure hands back one fragment of the list instead of what the agent wrote.
     *
     * @param array<array-key, mixed> $envelope
     *
     * @return array<array-key, mixed>|null
     */
    public static function firstRecord(array $envelope): ?array
    {
        if (! array_is_list($envelope)) {
            return $envelope;
        }

        foreach ($envelope as $record) {
            if (is_array($record)) {
                return $record;
            }
        }

        return null;
    }

    private static function isEnvelope(mixed $data): bool
    {
        return is_array($data) && self::firstRecord($data) !== null;
    }

    /**
     * Pull the single reply field out of a decoded agent JSON envelope.
     *
     * Critically this SELECTS one field — it never concatenates. The old behavior
     * (join every string value with `\n\n`) duplicated outbound emails whenever the
     * model emitted the body in more than one field (e.g. `{"body": "...\n\nSally",
     * "message": "..."}` rendered as `body + "\n\n" + message`), and leaked internal
     * fields (CRM notes, summaries) into the customer-facing reply. Prefer a known
     * content key; otherwise fall back to the longest single string value.
     *
     * @param array<array-key, mixed> $data
     */
    private static function pickResponseField(array $data): string
    {
        // Several records in one turn: the first one is the reply, the rest live on in the envelope.
        // Without this every value is an array, the string scan below finds nothing, and the empty
        // result reads to callers as "the agent said nothing" — throwing the whole turn away.
        if (array_is_list($data)) {
            $record = self::firstRecord($data);

            return $record !== null ? self::pickResponseField($record) : '';
        }

        foreach (['response', 'content', 'message', 'text', 'body', 'reply', 'answer', 'output'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                return $data[$key];
            }
        }

        $strings = array_values(array_filter($data, 'is_string'));
        if ($strings === []) {
            return '';
        }

        usort($strings, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $strings[0];
    }

    /**
     * Split a leading `Subject:` line off an email body the agent produced.
     *
     * Outreach agents emit the email as `Subject: ...\n\n<body>`. The email channel
     * needs the subject as the mail title, not buried in the body — otherwise the mail
     * goes out with a generic fallback subject and the real one shows up as the first
     * body line. Returns `['subject' => ?string, 'body' => string]`; subject is null
     * when no leading Subject line is present (leaves SMS/WhatsApp text untouched).
     */
    public static function extractEmailSubjectAndBody(string $text): array
    {
        if (! preg_match('/^\s*Subject:\s*(.+?)\s*$/im', $text, $matches)) {
            return ['subject' => null, 'body' => $text];
        }

        $body = preg_replace('/^\s*Subject:\s*.+?\s*$/im', '', $text, 1);

        return [
            'subject' => trim($matches[1]),
            'body' => ltrim((string) $body),
        ];
    }
}
