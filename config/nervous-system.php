<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Ledger
    |--------------------------------------------------------------------------
    |
    | Configuration for the Nervous System ledger — append-only event log
    | that backs every signal flowing through Kanvas.
    |
    */

    'ledger' => [
        /*
        | Hot-retention window in days. Events older than this are flushed
        | to S3 (or the configured disk) and deleted from MySQL by the
        | weekly archive sweeper.
        |
        | Set to a small value (e.g. 0 or 1) in test environments to
        | exercise the archive flow without waiting a full week.
        */
        'retention_days' => (int) env('NERVOUS_SYSTEM_RETENTION_DAYS', 7),

        /*
        | The filesystem disk where archive blobs are written. Must be
        | configured in config/filesystems.php. Defaults to "s3" in
        | production; tests may override to "local" or "fake".
        */
        'archive_disk' => env('NERVOUS_SYSTEM_ARCHIVE_DISK', 's3'),

        /*
        | Path prefix inside the disk. The full archive path becomes:
        |   {prefix}/{year}/{week}/events-{from}-to-{to}.jsonl.gz
        */
        'archive_path_prefix' => env('NERVOUS_SYSTEM_ARCHIVE_PATH_PREFIX', 'nervous-system'),

        /*
        | Batch size for the archive sweeper. Events are read from MySQL
        | in chunks of this size, written to the archive blob, then
        | deleted from MySQL. Larger = faster but more memory pressure.
        */
        'archive_chunk_size' => (int) env('NERVOUS_SYSTEM_ARCHIVE_CHUNK_SIZE', 5000),
    ],
];
