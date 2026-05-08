<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Enums;

/**
 * Names of Redis queues the NervousSystem domain dispatches onto. The
 * value MUST match the `--queue=...` flag in docker-compose worker
 * commands and any helm/k8s queue-pod configs.
 */
enum LedgerQueueEnum: string
{
    case LEDGER = 'ledger';
}
