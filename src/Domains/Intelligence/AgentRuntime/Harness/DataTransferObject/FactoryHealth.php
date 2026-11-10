<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * One `serve.sh health` snapshot of the factory Mac: what a coding chat cannot see from the agent's
 * replies. The agent only knows what its own commands printed, so an emulator that crash-loops after
 * "it's up and running" reaches the person only through this.
 */
class FactoryHealth extends Data
{
    /**
     * @param int                                                                 $at        the Mac's clock, so the next
     *                                                                                       snapshot asks for crashes since
     *                                                                                       exactly this one
     * @param list<string>                                                        $ios       booted simulators
     * @param array<string, string>|null                                          $android   serial => adb state; null
     *                                                                                       when adb itself did not answer
     * @param list<string>                                                        $emulators running Android AVDs
     * @param list<array{process: string, count: int, last: int, reason: string}> $crashes   since the snapshot asked for
     */
    public function __construct(
        public readonly int $at,
        public readonly array $ios = [],
        public readonly ?array $android = [],
        public readonly array $emulators = [],
        public readonly bool $metro = false,
        public readonly array $crashes = [],
    ) {
    }

    /**
     * What went wrong since `$previous`. Only losses are reported: the agent starts devices and Metro
     * itself and says so, but nothing tells anyone when they die. With no previous snapshot only the
     * crashes count, because there is nothing to compare the rest against.
     *
     * @return list<string>
     */
    public function problemsSince(?self $previous): array
    {
        $problems = array_map(
            static fn (array $crash): string => '💥 **' . $crash['process'] . '** crashed'
                . ($crash['count'] > 1 ? ' ' . $crash['count'] . ' times' : '')
                . ($crash['reason'] !== '' ? ' (' . $crash['reason'] . ')' : ''),
            $this->crashes
        );

        if ($previous === null) {
            return $problems;
        }

        foreach (array_diff($previous->ios, $this->ios) as $simulator) {
            $problems[] = '📱 iOS simulator **' . $simulator . '** shut down';
        }

        foreach (array_diff($previous->emulators, $this->emulators) as $emulator) {
            $problems[] = '🤖 Android emulator **' . $emulator . '** stopped';
        }

        if ($previous->android !== null && $this->android === null) {
            $problems[] = '🤖 adb stopped answering, so no Android device can be reached';
        }

        foreach ($this->androidDevicesLostSince($previous) as $problem) {
            $problems[] = $problem;
        }

        if ($previous->metro && ! $this->metro) {
            $problems[] = '⚡ Metro stopped: the app cannot load its JavaScript until it is started again';
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function androidDevicesLostSince(self $previous): array
    {
        // When adb is down every device looks gone; that is reported once, as adb.
        if ($this->android === null) {
            return [];
        }

        $lost = [];

        foreach ($previous->android ?? [] as $serial => $state) {
            $now = $this->android[$serial] ?? null;

            if ($state === 'device' && $now !== 'device') {
                $lost[] = '🤖 **' . $serial . '** ' . ($now === null ? 'disconnected from adb' : 'is now ' . $now);
            }
        }

        return $lost;
    }
}
