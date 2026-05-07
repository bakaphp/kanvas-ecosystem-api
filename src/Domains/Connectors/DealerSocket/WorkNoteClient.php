<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket;

use Exception;
use Illuminate\Support\Facades\Http;

class WorkNoteClient extends BaseClient
{
    public function insertWorkNote(array $data): array
    {
        $xml = $this->buildWorkNoteXML($data);

        return $this->postWorkNote($xml);
    }

    /**
     * POST WorkNote
     */
    private function postWorkNote(string $xml): array
    {
        $url = 'https://api.dealersocket.com/api/DealerSocket/WorkNote';

        $headers = $this->authService->getHMACHeaders($xml);
        $headers['Content-Type'] = 'application/xml';

        $response = Http::withHeaders($headers)
            ->withBody($xml, 'application/xml')
            ->post($url);

        return $this->parseWorkNoteResponse($response);
    }

    /**
     * Parse WorkNote response
     */
    private function parseWorkNoteResponse($response): array
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
     * Build WorkNote XML according to DealerSocket spec
     */
    private function buildWorkNoteXML(array $data): string
    {
        $vendor = $this->authService->getVendorName();
        $dealerId = $this->authService->getDealerId();

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<WorkNoteInsert xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <Vendor>{$vendor}</Vendor>
  <DealerId>{$dealerId}</DealerId>
XML;

        if (! empty($data['entityId'])) {
            $xml .= "\n  <EntityId>{$data['entityId']}</EntityId>";
        }

        if (! empty($data['eventId'])) {
            $xml .= "\n  <EventId>{$data['eventId']}</EventId>";
        }

        $batchId = $data['batchId'] ?? 0;
        $xml .= "\n  <BatchId>{$batchId}</BatchId>";

        if (! empty($data['externalId'])) {
            $type = $data['externalIdType'] ?? 'OemLeadId';
            $xml .= "\n  <ExternalId type=\"{$type}\">{$data['externalId']}</ExternalId>";
        }

        $note = $data['note'] ?? '';
        $xml .= "\n  <Note><![CDATA[{$note}]]></Note>";

        $xml .= "\n</WorkNoteInsert>";

        return $xml;
    }
}
