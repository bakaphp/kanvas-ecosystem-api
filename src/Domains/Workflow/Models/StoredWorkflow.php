<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Illuminate\Http\JsonResponse;
use Workflow\Models\StoredWorkflow as ModelsStoredWorkflow;

class StoredWorkflow extends ModelsStoredWorkflow
{
    protected $connection = 'workflow';

    public function getActivityName(): string
    {
        return class_basename($this->logs->first()->class);
    }

    public function getUnserializeArgument(): JsonResponse
    {        
        $unserialize = unserialize($this->arguments)->getClosure();
        $data = $unserialize();

        return response()->json($data);
    }

    public function getUnserializeOutput(): JsonResponse
    {
        $unserialize = unserialize($this->output)->getClosure();
        $data = $unserialize();

        return response()->json($data);
    }
}
