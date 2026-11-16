<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Concerns;

use Baka\Support\Str;
use Closure;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveDriveEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Exceptions\InvalidToolInputException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Throwable;

/** Requires the using tool to also use HasKanvasContext. */
trait CallsOneDrive
{
    protected ?OneDriveClient $oneDriveClient = null;

    public function withOneDriveClient(OneDriveClient $client): static
    {
        $this->oneDriveClient = $client;

        return $this;
    }

    protected function oneDrive(?string $drive = null): OneDriveClient
    {
        return $this->oneDriveClient ??= OneDriveClient::resolve($this->app, $this->company, $this->driveFrom($drive));
    }

    /**
     * Every OneDrive tool takes it, so the model can reach either drive when a company has both.
     */
    protected function driveProperty(): ToolProperty
    {
        return new ToolProperty(
            name: 'drive',
            type: PropertyType::STRING,
            description: 'Which OneDrive: "company" (this company\'s own) or "app" (shared by every company of the '
                . 'app). Omit it to use the company\'s own when it has one, otherwise the app\'s. Each result says '
                . 'which drive it came from; keep using that drive for the same files.',
            required: false,
        );
    }

    private function driveFrom(?string $drive): ?OneDriveDriveEnum
    {
        $drive = Str::trimToNull($drive);

        if ($drive === null) {
            return null;
        }

        return OneDriveDriveEnum::tryFrom(strtolower($drive))
            ?? throw new InvalidToolInputException('drive must be "company" or "app".');
    }

    /**
     * @return array<string, mixed>
     */
    protected function attempt(Closure $call): array
    {
        $result = $this->run($call);

        if ($this->oneDriveClient !== null) {
            $result['drive'] = $this->oneDriveClient->drive()->value;
        }

        // Without it the model reports a file outside the folder as one that does not exist.
        if ($this->oneDriveClient?->isConfined()) {
            $result['scope'] = 'Your access to this OneDrive is limited to one folder, and "/" is that folder. '
                . 'Nothing outside it can be reached: when something is not found, tell the user it may be outside '
                . 'the folder you can access, never that it does not exist.';
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function run(Closure $call): array
    {
        try {
            return ['success' => true, ...$call()];
        } catch (OneDriveException $e) {
            return ['success' => false, 'reason' => $e->reason->value, 'message' => $e->getMessage()];
        } catch (InvalidToolInputException $e) {
            return ['success' => false, 'reason' => 'invalid_input', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'reason' => 'request_failed',
                'message' => 'OneDrive request failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<mixed>
     */
    protected function decodeJson(?string $json, string $param): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new InvalidToolInputException($param . ' must be a valid JSON string.');
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    protected function decodeStringMap(?string $json, string $param): array
    {
        $map = $this->decodeJson($json, $param);

        foreach ($map as $value) {
            if (! is_string($value)) {
                throw new InvalidToolInputException($param . ' must be a JSON object of text to text.');
            }
        }

        if ($map !== [] && array_is_list($map)) {
            throw new InvalidToolInputException($param . ' must be a JSON object, not a list.');
        }

        return array_combine(array_map('strval', array_keys($map)), $map);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeCells(?string $json, string $param): array
    {
        $cells = $this->decodeJson($json, $param);

        if ($cells !== [] && array_is_list($cells)) {
            throw new InvalidToolInputException($param . ' must be a JSON object such as {"B2": 100}.');
        }

        foreach ($cells as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidToolInputException($param . ' values must be text, numbers, booleans or null.');
            }
        }

        return $cells;
    }

    /**
     * @return array<int, string>
     */
    protected function decodeStringList(?string $json, string $param): array
    {
        $list = $this->decodeJson($json, $param);

        if (! array_is_list($list)) {
            throw new InvalidToolInputException($param . ' must be a JSON list of text.');
        }

        foreach ($list as $value) {
            if (! is_string($value)) {
                throw new InvalidToolInputException($param . ' must be a JSON list of text.');
            }
        }

        return $list;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function decodeRows(?string $json, string $param): array
    {
        $rows = $this->decodeJson($json, $param);

        if (! array_is_list($rows)) {
            throw new InvalidToolInputException($param . ' must be a JSON list of lists.');
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! array_is_list($row)) {
                throw new InvalidToolInputException($param . ' must be a JSON list of lists.');
            }
        }

        return $rows;
    }
}
