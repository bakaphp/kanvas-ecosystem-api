<?php

namespace Kanvas\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Kanvas\Jobs\BatchLoggerJob;

class BatchLogger
{
    protected $redisKey = 'batchlogger:logs';

    public function log($message)
    {
        $maxLogBatchSize = config('kanvas.logger.max_log_batch_size');
        
        // Push the log message onto the Redis list
        Redis::rpush($this->redisKey, $message);

        // Check if the list length is 10 or more
        if (Redis::llen($this->redisKey) >= $maxLogBatchSize) {
            $this->flushLogs();
        }
    }

    public function flushLogs()
    {
        // Pop all logs from Redis
        $logs = Redis::lrange($this->redisKey, 0, -1);

        // Write logs to file
        foreach ($logs as $log) {
            BatchLoggerJob::dispatch($log);
        }

        // Remove the logs from Redis after flushing
        Redis::del($this->redisKey);
    }
}
