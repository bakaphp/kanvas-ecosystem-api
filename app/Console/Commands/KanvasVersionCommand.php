<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;

class KanvasVersionCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:version';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Whats the current version of kanvas niche you are running';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->newLine();
        $this->info('Kanvas Niche is running version : ' . AppEnums::VERSION->getValue());
        $this->newLine();

        $app = Apps::getById(2);
        $this->overwriteAppService($app);

        $this->info('=== Redis Diagnostics ===');

        try {
            // 1. Connection test
            $start = microtime(true);
            Redis::ping();
            $pingTime = (microtime(true) - $start) * 1000;
            $this->info("✓ Ping: {$pingTime}ms");

            // 2. Memory usage
            $info = Redis::info('memory');
            $this->info("\n--- Memory Usage ---");
            $this->line('Used Memory: ' . ($info['used_memory_human'] ?? 'N/A'));
            $this->line('Peak Memory: ' . ($info['used_memory_peak_human'] ?? 'N/A'));
            $this->line('Memory Fragmentation: ' . ($info['mem_fragmentation_ratio'] ?? 'N/A'));

            // 3. Stats
            $stats = Redis::info('stats');
            $this->info("\n--- Stats ---");
            $this->line('Total Connections: ' . ($stats['total_connections_received'] ?? 'N/A'));
            $this->line('Total Commands: ' . ($stats['total_commands_processed'] ?? 'N/A'));
            $this->line('Rejected Connections: ' . ($stats['rejected_connections'] ?? 'N/A'));
            $this->line('Evicted Keys: ' . ($stats['evicted_keys'] ?? 'N/A'));

            // 4. Command stats (top 10 most used)
            $this->info("\n--- Most Used Commands ---");
            $commandStats = Redis::info('commandstats');
            $commands = [];
            foreach ($commandStats as $key => $value) {
                if (strpos($key, 'cmdstat_') === 0) {
                    preg_match('/calls=(\d+)/', $value, $matches);
                    $calls = $matches[1] ?? 0;
                    $cmdName = str_replace('cmdstat_', '', $key);
                    $commands[$cmdName] = $calls;
                }
            }
            arsort($commands);
            foreach (array_slice($commands, 0, 10, true) as $cmd => $calls) {
                $this->line("  {$cmd}: {$calls} calls");
            }

            // 5. Slow log
            $this->info("\n--- Slow Commands (last 10) ---");
            $slowlog = Redis::slowlog('get', 10);
            if (empty($slowlog)) {
                $this->line('  No slow commands logged');
            } else {
                foreach ($slowlog as $entry) {
                    $duration = $entry[2] / 1000; // Convert microseconds to milliseconds
                    $command = implode(' ', array_slice($entry[3], 0, 3)); // First 3 parts of command
                    $this->line("  [{$duration}ms] {$command}");
                }
            }

            // 6. Connected clients
            $clients = Redis::info('clients');
            $this->info("\n--- Clients ---");
            $this->line('Connected Clients: ' . ($clients['connected_clients'] ?? 'N/A'));
            $this->line('Blocked Clients: ' . ($clients['blocked_clients'] ?? 'N/A'));

            // 7. Database size
            $dbsize = Redis::dbsize();
            $this->info("\n--- Database ---");
            $this->line("Total Keys: {$dbsize}");

            // 8. CPU (if available)
            $cpu = Redis::info('cpu');
            if (! empty($cpu)) {
                $this->info("\n--- CPU ---");
                $this->line('Used CPU (sys): ' . ($cpu['used_cpu_sys'] ?? 'N/A'));
                $this->line('Used CPU (user): ' . ($cpu['used_cpu_user'] ?? 'N/A'));
            }

            // 9. Check for expensive key patterns
            $this->info("\n--- Sample Key Analysis ---");
            $this->checkKeyPatterns();
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());

            return 1;
        }

        return 0;
    }

    private function checkKeyPatterns()
    {
        // Sample 100 random keys and check their types/sizes
        $keys = Redis::randomkey();
        if ($keys) {
            $type = Redis::type($keys);
            $this->line("Sample key: {$keys}");
            $this->line("  Type: {$type}");

            switch ($type) {
                case 'string':
                    $size = Redis::strlen($keys);
                    $this->line("  Size: {$size} bytes");

                    break;
                case 'list':
                    $len = Redis::llen($keys);
                    $this->line("  Length: {$len} items");

                    break;
                case 'set':
                    $card = Redis::scard($keys);
                    $this->line("  Cardinality: {$card} members");

                    break;
                case 'zset':
                    $card = Redis::zcard($keys);
                    $this->line("  Cardinality: {$card} members");

                    break;
                case 'hash':
                    $len = Redis::hlen($keys);
                    $this->line("  Fields: {$len}");

                    break;
            }
        }
    }
}
