<?php

declare(strict_types=1);

namespace Kanvas\Imports\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Services\CsvReaderService;
use Spatie\LaravelData\Data;

/**
 * A predefined mapper shipped with the platform (resources/import-templates). Applying it copies the
 * mapping into a company's own FilesystemMapper, so the template is a starting point, never a live
 * mapping. Options are named patches over the definition, which is what keeps per-company variants
 * (e.g. "price column first") out of hand-edited mapper copies.
 */
class ImportTemplate extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $systemModule,
        public readonly ?string $productType,
        public readonly array $requiredColumns,
        public readonly array $fileHeader,
        public readonly array $mapping,
        public readonly array $options = [],
        public readonly array $sourceDefaults = [],
    ) {
    }

    public static function fromDefinition(array $definition): self
    {
        return new self(
            key: (string) $definition['key'],
            version: (int) $definition['version'],
            name: (string) $definition['name'],
            description: $definition['description'] ?? null,
            systemModule: (string) $definition['system_module'],
            productType: $definition['product_type'] ?? null,
            requiredColumns: $definition['required_columns'] ?? [],
            fileHeader: $definition['file_header'] ?? [],
            mapping: $definition['mapping'],
            options: $definition['options'] ?? [],
            sourceDefaults: $definition['source_defaults'] ?? [],
        );
    }

    /**
     * @return array<string, string> every option, with its default filled in when not chosen
     */
    public function resolveOptions(array $chosen): array
    {
        $unknown = array_diff(array_keys($chosen), array_keys($this->options));
        if ($unknown !== []) {
            throw new ValidationException('Unknown option(s) for template ' . $this->key . ': ' . implode(', ', $unknown));
        }

        $resolved = [];
        foreach ($this->options as $option => $definition) {
            $choice = (string) ($chosen[$option] ?? $definition['default']);

            if (! array_key_exists($choice, $definition['choices'])) {
                throw new ValidationException(sprintf(
                    'Option %s must be one of: %s',
                    $option,
                    implode(', ', array_keys($definition['choices']))
                ));
            }

            $resolved[$option] = $choice;
        }

        ksort($resolved);

        return $resolved;
    }

    public function mappingFor(array $resolvedOptions): array
    {
        $definition = ['mapping' => $this->mapping];

        foreach ($resolvedOptions as $option => $choice) {
            foreach ($this->options[$option]['choices'][$choice] as $path => $value) {
                data_set($definition, $path, $value);
            }
        }

        return $definition['mapping'];
    }

    /**
     * Identifies "this template, this version, these options" so applying it twice reuses one mapper.
     */
    public function signature(array $resolvedOptions): string
    {
        return $this->key . '@' . $this->version . ($resolvedOptions === [] ? '' : '?' . http_build_query($resolvedOptions));
    }

    public function mapperName(array $resolvedOptions): string
    {
        $changed = [];
        foreach ($resolvedOptions as $option => $choice) {
            if ($choice !== $this->options[$option]['default']) {
                $changed[] = $option . '=' . $choice;
            }
        }

        return $changed === [] ? $this->name : $this->name . ' · ' . implode(', ', $changed);
    }

    /**
     * Scheduled-import fields this template pre-fills; explicit input always wins over them.
     */
    public function sourceInputDefaults(): array
    {
        return ['unpublish_missing' => (bool) ($this->sourceDefaults['unpublish_missing'] ?? false)];
    }

    /**
     * @return array{compatible: bool, missing_required: list<string>, missing_optional: list<string>, extra: list<string>}
     */
    public function compareHeader(array $header): array
    {
        $present = array_flip(array_map(fn ($column) => Str::lowerTrim((string) $column), $header));
        $expected = array_flip(array_map(fn (string $column) => Str::lowerTrim($column), $this->fileHeader));
        $isPresent = fn (string $column) => isset($present[Str::lowerTrim($column)]);

        $missingRequired = array_values(array_filter($this->requiredColumns, fn (string $column) => ! $isPresent($column)));

        return [
            'compatible' => $missingRequired === [],
            'missing_required' => $missingRequired,
            'missing_optional' => array_values(array_filter(
                $this->fileHeader,
                fn (string $column) => ! $isPresent($column) && ! in_array($column, $this->requiredColumns, true)
            )),
            'extra' => array_values(array_filter(
                array_map(fn ($column) => trim((string) $column), $header),
                fn (string $column) => ! isset($expected[Str::lowerTrim($column)])
            )),
        ];
    }

    public function compareHeaderOfFile(string $localCsvPath): array
    {
        $reader = CsvReaderService::fromPath($localCsvPath);
        $reader->setHeaderOffset(0);

        return $this->compareHeader($reader->getHeader());
    }
}
