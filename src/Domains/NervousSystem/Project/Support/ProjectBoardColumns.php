<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Support;

use Illuminate\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Project\Models\Project;

final class ProjectBoardColumns
{
    public const string CONFIG_KEY = 'board_columns';

    /**
     * @return array<int, array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}>
     */
    public function forProject(Project $project): array
    {
        $config = is_array($project->config) ? $project->config : [];
        $columns = $config[self::CONFIG_KEY] ?? null;

        if (! is_array($columns) || $columns === []) {
            return $this->defaults();
        }

        return $this->normalize($columns);
    }

    /**
     * @return array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}
     */
    public function create(Project $project, string $name, ?string $planStatus = null): array
    {
        $name = $this->validName($name);
        $columns = $this->forProject($project);
        $this->assertUniqueName($columns, $name);

        $status = $planStatus === null
            ? PlanStatusEnum::DRAFT
            : PlanStatusEnum::fromAlias($planStatus);
        $key = $this->uniqueKey($columns, Str::slug($name, '_'));
        $column = [
            'key' => $key,
            'name' => $name,
            'position' => count($columns),
            'plan_status' => $status->value,
            'legacy_statuses' => [$status->value],
        ];

        $columns[] = $column;
        $this->persist($project, $columns);

        return $column;
    }

    /**
     * @return array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}
     */
    public function rename(Project $project, string $key, string $name): array
    {
        $name = $this->validName($name);
        $columns = $this->forProject($project);
        $this->assertUniqueName($columns, $name, $key);
        $renamed = null;

        foreach ($columns as &$column) {
            if ($column['key'] !== $key) {
                continue;
            }

            $column['name'] = $name;
            $renamed = $column;
            break;
        }
        unset($column);

        if ($renamed === null) {
            throw new ValidationException("Project board column {$key} was not found.");
        }

        $this->persist($project, $columns);

        return $renamed;
    }

    /**
     * @param array<int, string> $keys
     * @return array<int, array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}>
     */
    public function reorder(Project $project, array $keys): array
    {
        $columns = $this->forProject($project);
        $currentKeys = array_column($columns, 'key');
        $keys = array_values($keys);

        if (count($keys) !== count(array_unique($keys))) {
            throw new ValidationException('Project board column order contains duplicate keys.');
        }

        $expected = $currentKeys;
        $received = $keys;
        sort($expected);
        sort($received);

        if ($expected !== $received) {
            throw new ValidationException('Project board column order must contain every existing column exactly once.');
        }

        $byKey = [];
        foreach ($columns as $column) {
            $byKey[$column['key']] = $column;
        }

        $ordered = [];
        foreach ($keys as $position => $key) {
            $column = $byKey[$key];
            $column['position'] = $position;
            $ordered[] = $column;
        }

        $this->persist($project, $ordered);

        return $ordered;
    }

    /**
     * @return array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}
     */
    public function find(Project $project, string $key): array
    {
        foreach ($this->forProject($project) as $column) {
            if ($column['key'] === $key) {
                return $column;
            }
        }

        throw new ValidationException("Project board column {$key} was not found.");
    }

    /**
     * @return array<int, array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}>
     */
    public function defaults(): array
    {
        return [
            [
                'key' => 'todo',
                'name' => 'TODO',
                'position' => 0,
                'plan_status' => PlanStatusEnum::DRAFT->value,
                'legacy_statuses' => [
                    PlanStatusEnum::INTAKE->value,
                    PlanStatusEnum::DRAFT->value,
                    PlanStatusEnum::AWAITING_APPROVAL->value,
                ],
            ],
            [
                'key' => 'in_progress',
                'name' => 'IN PROGRESS',
                'position' => 1,
                'plan_status' => PlanStatusEnum::ACTIVE->value,
                'legacy_statuses' => [PlanStatusEnum::ACTIVE->value],
            ],
            [
                'key' => 'blocked',
                'name' => 'BLOCKED',
                'position' => 2,
                'plan_status' => PlanStatusEnum::BLOCKED->value,
                'legacy_statuses' => [PlanStatusEnum::BLOCKED->value, PlanStatusEnum::FAILED->value],
            ],
            [
                'key' => 'done',
                'name' => 'DONE',
                'position' => 3,
                'plan_status' => PlanStatusEnum::DONE->value,
                'legacy_statuses' => [PlanStatusEnum::DONE->value, PlanStatusEnum::CANCELLED->value],
            ],
        ];
    }

    /**
     * @param array<int, mixed> $columns
     * @return array<int, array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}>
     */
    private function normalize(array $columns): array
    {
        $normalized = [];

        foreach (array_values($columns) as $position => $column) {
            if (! is_array($column) || ! isset($column['key'], $column['name'])) {
                continue;
            }

            $status = PlanStatusEnum::fromAlias(
                (string) ($column['plan_status'] ?? PlanStatusEnum::DRAFT->value),
            );
            $legacyStatuses = array_values(array_filter(
                (array) ($column['legacy_statuses'] ?? [$status->value]),
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            ));

            $normalized[] = [
                'key' => (string) $column['key'],
                'name' => (string) $column['name'],
                'position' => $position,
                'plan_status' => $status->value,
                'legacy_statuses' => $legacyStatuses === [] ? [$status->value] : $legacyStatuses,
            ];
        }

        return $normalized === [] ? $this->defaults() : $normalized;
    }

    /**
     * @param array<int, array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}> $columns
     */
    private function persist(Project $project, array $columns): void
    {
        $config = is_array($project->config) ? $project->config : [];
        $config[self::CONFIG_KEY] = array_values($columns);
        $project->config = $config;
        $project->saveOrFail();
    }

    private function validName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new ValidationException('A project board column name is required.');
        }

        if (mb_strlen($name) > 80) {
            throw new ValidationException('A project board column name may not exceed 80 characters.');
        }

        return $name;
    }

    /**
     * @param array<int, array{key: string, name: string}> $columns
     */
    private function assertUniqueName(array $columns, string $name, ?string $exceptKey = null): void
    {
        foreach ($columns as $column) {
            if ($column['key'] !== $exceptKey && strcasecmp($column['name'], $name) === 0) {
                throw new ValidationException("Project board column {$name} already exists.");
            }
        }
    }

    /**
     * @param array<int, array{key: string}> $columns
     */
    private function uniqueKey(array $columns, string $candidate): string
    {
        $candidate = $candidate !== '' ? $candidate : 'column';
        $keys = array_column($columns, 'key');
        $key = $candidate;
        $suffix = 2;

        while (in_array($key, $keys, true)) {
            $key = $candidate . '_' . $suffix;
            $suffix++;
        }

        return $key;
    }
}
