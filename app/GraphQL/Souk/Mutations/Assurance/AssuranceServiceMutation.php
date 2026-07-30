<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Assurance;

use Kanvas\Souk\Assurance\Actions\ProcessAssuranceServiceAction;
use Kanvas\Souk\Assurance\DataTransferObject\AssuranceServiceInput;
use Kanvas\Souk\Assurance\Models\AssuranceService;
use Exception;
use Illuminate\Support\Facades\Log;

class AssuranceServiceMutation
{
    /**
     * Process a generic assurance service request.
     *
     * @param mixed $root
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function processAssuranceService(mixed $root, array $args): array
    {
        try {
            Log::info('Processing Assurance Service Request', $args['input']);

            $assuranceServiceInput = AssuranceServiceInput::from($args['input']);
            $action = new ProcessAssuranceServiceAction($assuranceServiceInput);
            $result = $action->execute();

            return [
                'status' => 'success',
                'message' => 'Assurance service request processed successfully.',
                'data' => $result
            ];
        } catch (Exception $e) {
            Log::error('Error processing Assurance Service Request: ' . $e->getMessage(), ['exception' => $e]);
            return [
                'status' => 'error',
                'message' => 'Failed to process assurance service request: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
}
