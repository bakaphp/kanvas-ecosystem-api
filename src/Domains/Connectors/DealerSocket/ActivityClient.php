<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket;

use Exception;
use Illuminate\Support\Facades\Http;

class ActivityClient extends BaseClient
{
    /**
     * Save Activity
     * DealerSocket determines based on presence of ActivityId:
     * - If ActivityId is present PUT
     * - If ActivityId is absent POST
     */
    public function saveActivity(array $data): array
    {
        $xml = $this->buildActivityXML($data);

        $hasActivityId = ! empty($data['activityId']);

        return $this->postActivity($xml, $hasActivityId);
    }

    /**
     * POST or PUT activity
     */
    private function postActivity(string $xml, bool $usePut = false): array
    {
        $url = 'https://api.dealersocket.com/api/DealerSocket/Activity';

        $headers = $this->authService->getHMACHeaders($xml);
        $headers['Content-Type'] = 'application/xml';

        $method = $usePut ? 'put' : 'post';

        $response = Http::withHeaders($headers)
            ->withBody($xml, 'application/xml')
            ->$method($url);

        return $this->parseActivityResponse($response);
    }

    /**
     * Parse activity response
     */
    private function parseActivityResponse($response): array
    {
        if ($response->failed()) {
            return [
                'success' => false,
                'error' => 'HTTP Error: ' . $response->status(),
                'body' => $response->body(),
            ];
        }

        try {
            $xml = simplexml_load_string($response->body());

            if ($xml === false) {
                throw new Exception('Failed to parse XML response');
            }

            $success = strtolower((string)$xml->Success) === 'true';

            $result = [
                'success' => $success,
                'activityId' => (string)($xml->ActivityID ?? $xml->ActivityId ?? ''),
                'errorCode' => (string)($xml->ErrorCode ?? ''),
                'errorMessage' => (string)($xml->ErrorMessage ?? ''),
                'stackTrace' => (string)($xml->StackTrace ?? ''),
                'rawXml' => $response->body(),
            ];

            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Parse Error: ' . $e->getMessage(),
                'body' => $response->body(),
            ];
        }
    }

    /**
     * Build Activity XML according to DealerSocket spec
     */
    private function buildActivityXML(array $data): string
    {
        $vendor = $this->authService->getVendorName();
        $dealerId = $this->authService->getDealerId();

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<ActivityInsert xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <Vendor>{$vendor}</Vendor>
  <DealerId>{$dealerId}</DealerId>
XML;

        // EntityId (Required unless ExternalId is used)
        if (! empty($data['entityId'])) {
            $xml .= "\n  <EntityId>{$data['entityId']}</EntityId>";
        }

        // EventId (Required unless ExternalId is used)
        if (! empty($data['eventId'])) {
            $xml .= "\n  <EventId>{$data['eventId']}</EventId>";
        }

        // ExternalId and BatchId (Alternative to EntityId/EventId)
        if (! empty($data['externalId'])) {
            $xml .= "\n  <ExternalId>{$data['externalId']}</ExternalId>";
        }

        if (isset($data['batchId'])) {
            $xml .= "\n  <BatchId>{$data['batchId']}</BatchId>";
        }

        if (! empty($data['activityTaskType'])) {
            $xml .= "\n  <ActivityTaskType>{$data['activityTaskType']}</ActivityTaskType>";
        } elseif (! empty($data['activityType'])) {
            $xml .= "\n  <ActivityType>{$data['activityType']}</ActivityType>";
        }

        $dueDateTime = $data['dueDateTime'] ?? now()->toIso8601String();
        $xml .= "\n  <DueDateTime>{$dueDateTime}</DueDateTime>";

        if (! empty($data['assignedToUserId'])) {
            $xml .= "\n  <AssignedToUserId>{$data['assignedToUserId']}</AssignedToUserId>";
        } elseif (! empty($data['assignedToUser'])) {
            $xml .= "\n  <AssignedToUser>{$data['assignedToUser']}</AssignedToUser>";
        }

        if (! empty($data['status'])) {
            $xml .= "\n  <Status>{$data['status']}</Status>";
        }

        if (! empty($data['activityId'])) {
            $xml .= "\n  <ActivityId>{$data['activityId']}</ActivityId>";
        }

        // Note
        if (isset($data['note'])) {
            $xml .= "\n  <Note>{$data['note']}</Note>";
        }

        $xml .= "\n</ActivityInsert>";

        return $xml;
    }
}
