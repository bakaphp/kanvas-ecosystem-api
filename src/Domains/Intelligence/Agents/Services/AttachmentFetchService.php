<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Http\Exceptions\SsrfException;
use Baka\Http\SafeUrlFetcher;
use Baka\Support\Str;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads a chat attachment's bytes. One unreadable attachment must never sink the turn, so a failure
 * returns null instead of throwing.
 *
 * Attachment URLs come from the person chatting (often an LLM-invented link), so a 4xx from the
 * remote host or an SSRF rejection is their input, not our fault — logged, never sent to Sentry
 * (KANVAS-ECOSYSTEM-6EJ). Everything else is still reported.
 */
final class AttachmentFetchService
{
    public static function fetch(string $source): ?string
    {
        try {
            if (preg_match('#^https?://#i', $source)) {
                return SafeUrlFetcher::fetch($source);
            }

            $raw = file_get_contents($source);

            return $raw === false ? null : $raw;
        } catch (Throwable $e) {
            if (self::isRejectedSource($e)) {
                Log::warning('Chat attachment could not be fetched', [
                    'source' => $source,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            } else {
                report($e);
            }

            return null;
        }
    }

    public static function isRejectedSource(Throwable $e): bool
    {
        return $e instanceof ClientException || $e instanceof SsrfException;
    }

    /**
     * Told to the model so it doesn't answer as if it saw an attachment that never arrived.
     */
    public static function unavailableNote(string $source): string
    {
        $name = Str::fileNameFromUrl($source, 'an attachment');

        return "[Could not load {$name} — it is not visible to you. "
            . 'If the person refers to it, tell them you could not open it.]';
    }
}
