<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\Tool;
use ReflectionClass;
use Throwable;

class AppendToolInstructionsAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly AppInterface $app,
    ) {
    }

    public function execute(): void
    {
        $toolIds = $this->agent->selectedTools()->pluck('id')->all();
        $lines = [];

        foreach ($toolIds as $toolId) {
            $tool = Tool::query()
                ->where('id', (int) $toolId)
                ->whereIn('apps_id', [0, $this->app->getId()])
                ->first();

            if ($tool === null || $tool->handler === null || ! class_exists($tool->handler)) {
                continue;
            }

            // Some handlers (e.g. DynamicSubAgent) require constructor args
            // that aren't available here — skip them rather than crash the
            // whole toggle. Reflect on the constructor first so we don't try
            // to instantiate a handler that needs context, and wrap the
            // instructions() call so a faulty hint doesn't break the action.
            $reflection = new ReflectionClass($tool->handler);
            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            try {
                $instance = new $tool->handler();
                if (method_exists($instance, 'instructions') && ($hint = $instance->instructions()) !== null && $hint !== '') {
                    $lines[] = '- ' . $hint;
                }
            } catch (Throwable) {
                continue;
            }
        }

        $base = $this->agent->instructions ?? $this->agent->type?->instructions ?? '';
        $sectionHeader = '## Tool Usage Guidelines';

        $pos = strpos($base, "\n\n" . $sectionHeader);
        if ($pos !== false) {
            $base = substr($base, 0, $pos);
        } elseif (str_starts_with($base, $sectionHeader)) {
            $base = '';
        }

        if (empty($lines)) {
            $this->agent->instructions = $base !== '' ? $base : null;
            $this->agent->save();

            return;
        }

        $toolSection = $sectionHeader . "\n" . implode("\n", $lines);
        $this->agent->instructions = $base !== '' ? $base . "\n\n" . $toolSection : $toolSection;
        $this->agent->save();
    }
}
