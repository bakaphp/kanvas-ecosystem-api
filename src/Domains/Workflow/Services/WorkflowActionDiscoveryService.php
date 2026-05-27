<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Services;

use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\KanvasActivity;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

class WorkflowActionDiscoveryService
{
    /** @var list<string> */
    private array $scanPaths;

    /**
     * @param list<string>|null $scanPaths Absolute paths to scan for classes carrying the attribute.
     */
    public function __construct(?array $scanPaths = null)
    {
        $this->scanPaths = $scanPaths ?? [
            base_path('src'),
            base_path('app'),
        ];
    }

    /**
     * @return list<array{class: class-string, name: string, description: ?string}>
     */
    public function discover(): array
    {
        $found = [];

        $finder = new Finder();
        $finder->files()->in($this->scanPaths)->name('*.php');

        foreach ($finder as $file) {
            $fqcn = $this->fqcnFromFile($file->getPathname());
            if ($fqcn === null) {
                continue;
            }

            try {
                if (! class_exists($fqcn)) {
                    continue;
                }

                $reflection = new ReflectionClass($fqcn);
            } catch (Throwable) {
                continue;
            }

            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            $attributes = $reflection->getAttributes(
                WorkflowAction::class,
                ReflectionAttribute::IS_INSTANCEOF
            );
            if ($attributes === []) {
                continue;
            }

            $meta = $attributes[0]->newInstance();

            $found[] = [
                'class' => $fqcn,
                'name' => $meta->name ?? class_basename($fqcn),
                'description' => $meta->description,
            ];
        }

        return $found;
    }

    /**
     * Find concrete `KanvasActivity` / `ProcessWebhookJob` subclasses that are missing the
     * `#[WorkflowAction]` attribute. Used by the guardrail test to catch forgotten tags.
     *
     * @return list<class-string>
     */
    public function findUntagged(): array
    {
        $missing = [];

        $finder = new Finder();
        $finder->files()->in($this->scanPaths)->name('*.php');

        foreach ($finder as $file) {
            $fqcn = $this->fqcnFromFile($file->getPathname());
            if ($fqcn === null) {
                continue;
            }

            try {
                if (! class_exists($fqcn)) {
                    continue;
                }

                $reflection = new ReflectionClass($fqcn);
            } catch (Throwable) {
                continue;
            }

            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            $isCandidate = $reflection->isSubclassOf(KanvasActivity::class)
                || $reflection->isSubclassOf(ProcessWebhookJob::class);
            if (! $isCandidate) {
                continue;
            }

            if ($reflection->getAttributes(WorkflowAction::class, ReflectionAttribute::IS_INSTANCEOF) === []) {
                $missing[] = $fqcn;
            }
        }

        return $missing;
    }

    /**
     * Parse `namespace ...;` + `class ...` (or final/abstract class, plus enum) from a PHP file
     * to build the FQCN without loading the file. Returns null if no class declaration is present.
     */
    private function fqcnFromFile(string $path): ?string
    {
        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        if (! preg_match('/^\s*namespace\s+([^;{]+)\s*[;{]/m', $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match(
            '/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m',
            $contents,
            $classMatch
        )) {
            return null;
        }

        return trim($nsMatch[1]) . '\\' . $classMatch[1];
    }
}
