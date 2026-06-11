<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Helpers;

use Baka\Support\Str;

class ChatHelper
{
    public static function extractTextFromResponse(string $response): string
    {
        $data = self::decodeJsonEnvelope($response);
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
        $data = self::decodeJsonEnvelope($response);
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
     * @return array<array-key, mixed>|null
     */
    private static function decodeJsonEnvelope(string $response): ?array
    {
        if (Str::isJson($response)) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches)) {
            $data = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }

        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $data = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }

        return null;
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
