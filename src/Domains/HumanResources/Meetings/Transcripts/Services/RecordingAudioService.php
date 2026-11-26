<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Transcripts\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Kanvas\Exceptions\ValidationException;
use Kanvas\HumanResources\Meetings\Recordings\Models\MeetingRecording;

class RecordingAudioService
{
    /**
     * Gemini's transcribe model takes 30 minutes of audio per request once diarization is on and
     * silently drops the rest, so a recording is cut below that.
     */
    public const int CHUNK_SECONDS = 1500;

    public const string CHUNK_MIME_TYPE = 'audio/webm';

    /**
     * Copies the recording next to the worker and cuts it into chunks small enough to ride inline in
     * a request: mono 16 kHz Opus at 32 kbps is about 6 MB per chunk, where the stereo original at
     * the client's bitrate would pass the inline limit. The bytes are whatever a client uploaded, so
     * the demuxer is pinned to WebM/Matroska and ffmpeg may open nothing but that one file.
     *
     * @return list<array{path: string, offset_ms: int}> in recording order
     */
    public function chunks(MeetingRecording $recording, string $workDir): array
    {
        File::ensureDirectoryExists($workDir);

        $source = $workDir . '/source.webm';
        $stream = Storage::disk($recording->storage_disk)->readStream($recording->storage_key);

        if ($stream === null) {
            throw new ValidationException('The recording file is not in storage');
        }

        file_put_contents($source, $stream);
        fclose($stream);

        $result = Process::timeout(1800)->run([
            'ffmpeg', '-y', '-loglevel', 'error',
            '-protocol_whitelist', 'file', '-f', 'matroska', '-i', $source,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libopus', '-b:a', '32k',
            '-f', 'segment', '-segment_time', (string) self::CHUNK_SECONDS, '-reset_timestamps', '1',
            $workDir . '/part-%04d.webm',
        ]);

        if ($result->failed()) {
            throw new ValidationException('The recording could not be read as audio: ' . trim($result->errorOutput()));
        }

        $paths = File::glob($workDir . '/part-*.webm');
        sort($paths);

        return array_map(
            fn (string $path, int $index): array => [
                'path' => $path,
                'offset_ms' => $index * self::CHUNK_SECONDS * 1000,
            ],
            $paths,
            array_keys($paths),
        );
    }
}
