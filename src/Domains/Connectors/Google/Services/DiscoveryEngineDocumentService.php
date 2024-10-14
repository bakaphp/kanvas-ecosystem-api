<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Services;

use Google\ApiCore\ApiException;
use Google\Cloud\DiscoveryEngine\V1\Client\DocumentServiceClient;
use Google\Cloud\DiscoveryEngine\V1\CreateDocumentRequest;
use Google\Cloud\DiscoveryEngine\V1\Document;
use Google\Cloud\DiscoveryEngine\V1\Document\Content;
use Google\Cloud\DiscoveryEngine\V1\GetDocumentRequest;
use Google\Cloud\DiscoveryEngine\V1\UpdateDocumentRequest;
use Kanvas\Social\Messages\Models\Message;

class DiscoveryEngineDocumentService extends DiscoveryEngineService
{
    public function updateOrCreateDocument(Message $message): Document
    {
        // Create the document
        $document = $this->buildDocument($message);

        $formattedParent = $this->getFormattedParent();
        $documentName = $this->getDocumentName($message->getId());

        // Create a client.
        $documentServiceClient = new DocumentServiceClient([
            'credentials' => $this->googleClientConfig,
        ]);

        try {
            // Attempt to retrieve the document
            $existingDocument = $documentServiceClient->getDocument(
                (new GetDocumentRequest())->setName($documentName)
            );

            if ($existingDocument) {
                return $this->updateDocument($message);
            }
        } catch (ApiException $e) {
            if ($e->getStatus() !== 'NOT_FOUND') {
                throw $e; // Re-throw other exceptions
            }
        }

        $request = (new CreateDocumentRequest())
            ->setParent($formattedParent)
            ->setDocument($document)
            ->setDocumentId($message->getId());

        return $documentServiceClient->createDocument($request);
    }

    public function updateDocument(Message $message): Document
    {
        $document = $this->buildDocument($message);
        $documentName = $this->getDocumentName($message->getId());

        // Set the name for updating the document
        $document->setName($documentName);

        $documentServiceClient = new DocumentServiceClient([
            'credentials' => $this->googleClientConfig,
        ]);

        $request = (new UpdateDocumentRequest())->setDocument($document);

        return $documentServiceClient->updateDocument($request);
    }

    protected function buildDocument(Message $message): Document
    {
        $document = new Document();
        $document->setId($message->getId()); // Set document ID

        $content = new Content();
        $data = ! is_array($message->message) ? [$message->message] : $message->message;
        $data['title'] = ! empty($data['title']) ? $data['title'] : ($message->slug ?? $message->uuid);
        $data['uri'] = ! empty($data['title']) ? $data['title'] : ($message->slug ?? $message->uuid);
        $data['categories'] = $message->tags()->count() ? $message->tags()->pluck('name')->toArray() : ['message'];
        $data['available_time'] = $message->created_at->toRfc3339String();

        $jsonContent = json_encode($data);
        $content->setRawBytes($jsonContent);
        $content->setMimeType('application/json');

        $document->setJsonData($jsonContent); // Set the JSON data

        return $document;
    }

    protected function getFormattedParent(): string
    {
        return DocumentServiceClient::branchName(
            $this->googleRecommendationConfig['projectId'],
            $this->googleRecommendationConfig['location'],
            $this->googleRecommendationConfig['dataSource'],
            $this->googleRecommendationConfig['branch']
        );
    }

    protected function getDocumentName(string|int $documentId): string
    {
        return DocumentServiceClient::documentName(
            $this->googleRecommendationConfig['projectId'],
            $this->googleRecommendationConfig['location'],
            $this->googleRecommendationConfig['dataSource'],
            $this->googleRecommendationConfig['branch'],
            (string) $documentId
        );
    }
}
