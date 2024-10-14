<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Google\Services\DiscoveryEngineDocumentService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;

use function Sentry\captureException;

class SyncMessageToDocumentAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
    ) {
    }

    public function execute(
        ?MessageType $messageType = null,
        array $messagesId = [],
        int $messagePerBatch = 100
    ): array {
        $query = Message::fromApp($this->app)
            ->orderBy('id', 'DESC');

        if ($messageType) {
            $query->where('message_types_id', $messageType->id);
        }

        if (! empty($messagesId)) {
            $query->whereIn('id', $messagesId);
        }

        $messageRecommendation = new DiscoveryEngineDocumentService($this->app, $this->company);
        $totalProcessed = [
            'total' => 0,
            'success' => 0,
            'error' => 0,
        ];

        $query->chunk($messagePerBatch, function ($messages) use ($messageRecommendation, &$totalProcessed) {
            foreach ($messages as $message) {
                $totalProcessed['total']++;

                try {
                    $messageRecommendation->updateOrCreateDocument($message);
                    $totalProcessed['success']++;
                } catch (Exception $e) {
                    $totalProcessed['error']++;
                    Log::error($e->getMessage());
                    captureException($e);
                }
            }
        });

        return $totalProcessed;
    }
}
