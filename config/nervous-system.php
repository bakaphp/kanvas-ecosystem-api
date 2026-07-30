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

        /*
        | Event types the sweeper must NEVER archive-and-delete, regardless
        | of age. The ledger is an ephemeral 7-day stream for audit/telemetry,
        | but some event types back a durable product feed that is read long
        | after emission (e.g. `people.enriched` powers the enrichment feed and
        | `people.email_validated` powers the bounce/invalid-email export). A
        | one-time bulk-enriched cohort would otherwise vanish wholesale the
        | moment it crossed the retention window. Comma-separated env override.
        */
        'preserve_event_types' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NERVOUS_SYSTEM_PRESERVE_EVENT_TYPES', 'people.enriched,people.email_validated')),
        ))),
    ],
];
