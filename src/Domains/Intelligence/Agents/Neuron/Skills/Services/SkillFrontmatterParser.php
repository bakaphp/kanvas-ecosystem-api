<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Skills\Services;

use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Neuron\Skills\DataTransferObject\SkillDefinition;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class SkillFrontmatterParser
{
    public function parse(string $filePath, string $source = ''): SkillDefinition
    {
        $fallbackName = basename(dirname($filePath));
        $stream = @fopen($filePath, 'rb');

        if ($stream === false) {
            Log::warning('Unable to read skill frontmatter; using fallback metadata.', ['path' => $filePath]);

            return new SkillDefinition(name: $fallbackName, description: '', filePath: $filePath, source: $source);
        }

        try {
            $firstLine = fgets($stream);
            if ($firstLine === false || trim($firstLine) !== '---') {
                Log::warning('Skill markdown is missing opening YAML frontmatter delimiter.', ['path' => $filePath]);

                return new SkillDefinition(name: $fallbackName, description: '', filePath: $filePath, source: $source);
            }

            $frontmatter = '';
            $closed = false;
            while (($line = fgets($stream)) !== false) {
                if (trim($line) === '---') {
                    $closed = true;
                    break;
                }

                $frontmatter .= $line;
            }

            if (! $closed) {
                Log::warning('Skill markdown is missing closing YAML frontmatter delimiter.', ['path' => $filePath]);

                return new SkillDefinition(name: $fallbackName, description: '', filePath: $filePath, source: $source);
            }

            try {
                $metadata = Yaml::parse($frontmatter);
            } catch (ParseException $exception) {
                Log::warning('Skill YAML frontmatter could not be parsed.', [
                    'path' => $filePath,
                    'exception' => $exception->getMessage(),
                ]);

                return new SkillDefinition(name: $fallbackName, description: '', filePath: $filePath, source: $source);
            }

            if (! is_array($metadata)) {
                Log::warning('Skill YAML frontmatter must be a mapping.', ['path' => $filePath]);

                return new SkillDefinition(name: $fallbackName, description: '', filePath: $filePath, source: $source);
            }

            $name = $metadata['name'] ?? null;
            $description = $metadata['description'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                Log::warning('Skill YAML frontmatter has no valid name; using its directory name.', ['path' => $filePath]);
                $name = $fallbackName;
            }

            if (! is_string($description)) {
                Log::warning('Skill YAML frontmatter has no valid description; using a blank description.', ['path' => $filePath]);
                $description = '';
            }

            return new SkillDefinition(name: trim($name), description: trim($description), filePath: $filePath, source: $source);
        } finally {
            fclose($stream);
        }
    }
}
