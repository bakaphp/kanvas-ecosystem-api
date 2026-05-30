<?php

declare(strict_types=1);

namespace Baka\Discovery;

use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Base for services that discover classes carrying a marker attribute by scanning
 * source directories. Subclasses declare which attribute to look for, which classes
 * are expected to carry it, and how to shape each discovered entry — `discover()`
 * and `findUntagged()` are provided and enforced for every discovery service.
 *
 * @template TEntry of array<string, mixed>
 */
abstract class AttributeClassDiscovery
{
    /** @var list<string> */
    protected array $scanPaths;

    /**
     * @param list<string>|null $scanPaths Absolute paths to scan for classes.
     */
    public function __construct(?array $scanPaths = null)
    {
        $this->scanPaths = $scanPaths ?? [
            base_path('src'),
            base_path('app'),
        ];
    }

    /**
     * @return class-string The marker attribute to look for.
     */
    abstract protected function attributeClass(): string;

    /**
     * Whether a concrete class is expected to carry the attribute (drives findUntagged).
     */
    abstract protected function isCandidate(ReflectionClass $reflection): bool;

    /**
     * @return TEntry
     */
    abstract protected function toEntry(ReflectionClass $reflection, object $attribute): array;

    /**
     * @return list<TEntry>
     */
    public function discover(): array
    {
        $found = [];

        foreach ($this->reflectConcreteClasses() as $reflection) {
            $attributes = $reflection->getAttributes($this->attributeClass(), ReflectionAttribute::IS_INSTANCEOF);
            if ($attributes === []) {
                continue;
            }

            $found[] = $this->toEntry($reflection, $attributes[0]->newInstance());
        }

        return $found;
    }

    /**
     * Concrete candidate classes missing the marker attribute — drives the guardrail test.
     *
     * @return list<class-string>
     */
    public function findUntagged(): array
    {
        $missing = [];

        foreach ($this->reflectConcreteClasses() as $reflection) {
            if (! $this->isCandidate($reflection)) {
                continue;
            }

            if ($reflection->getAttributes($this->attributeClass(), ReflectionAttribute::IS_INSTANCEOF) === []) {
                $missing[] = $reflection->getName();
            }
        }

        return $missing;
    }

    /**
     * @return iterable<ReflectionClass>
     */
    protected function reflectConcreteClasses(): iterable
    {
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

            yield $reflection;
        }
    }

    /**
     * Build the FQCN from `namespace ...;` + `class ...` without loading the file.
     */
    protected function fqcnFromFile(string $path): ?string
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
