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
        | Rows hydrated per read by the archive sweeper. Keep it small: one
        | ledger row can carry a multi-MB payload, and 5000 of those in a
        | single fetch exhausted the 1GB CLI memory limit.
        */
        'archive_chunk_size' => (int) env('NERVOUS_SYSTEM_ARCHIVE_CHUNK_SIZE', 500),

        /*
        | Events per archive blob. Each segment is uploaded, recorded and
        | deleted from MySQL before the next one starts, so a run that dies
        | midway keeps its progress. Also bounds what the restore action
        | loads per blob.
        */
        'archive_segment_size' => (int) env('NERVOUS_SYSTEM_ARCHIVE_SEGMENT_SIZE', 50000),

        /*
        | Event types the sweeper must NEVER archive-and-delete, regardless
        | of age. The ledger is an ephemeral 7-day stream for audit/telemetry,
        | but some event types back a durable product feed that is read long
        | after emission (e.g. `people.enriched` powers the enrichment feed and
        | `people.email_validated` powers the bounce/invalid-email export). A
        | one-time bulk-enriched cohort would otherwise vanish wholesale the
        | moment it crossed the retention window. Comma-separated env override.
        |
        | `agent.knowledge.saved` backs agent long-term memory (the `remember`
        | tool) — it is durable by design and MUST survive the sweep, since the
        | whole point is recall long after emission.
        */
        'preserve_event_types' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NERVOUS_SYSTEM_PRESERVE_EVENT_TYPES', 'people.enriched,people.email_validated,agent.knowledge.saved')),
        ))),
    ],
];
