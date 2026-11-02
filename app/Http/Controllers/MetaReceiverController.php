<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Http\Request;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Handlers\MetaWhatsAppHandler;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\MetaInbound;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The one URL every app's WABAs post to. The number in the payload names the company that claims
 * it; the request runs as that company's app and goes to that app's receiver.
 */
class MetaReceiverController extends ReceiverController
{
    use KanvasJobsTrait;

    /**
     * Meta's verify GET carries no number, only the token the WABAs were subscribed with.
     */
    public function verifyMeta(Request $request): SymfonyResponse
    {
        $challenge = new MetaInbound()->answerChallenge($request, CommunicationReceivers::platformApp());

        return $challenge === null
            ? response()->json(['message' => 'Unauthorized'], 403)
            : response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function storeMeta(Request $request): SymfonyResponse
    {
        $payload = json_decode($request->getContent(), true);
        $claim = new MetaInbound()->claimFor(is_array($payload) ? $payload : []);

        // Acknowledged, so Meta stops retrying a number no company takes.
        if ($claim === null) {
            return response()->json(['message' => 'Receiver processed']);
        }

        $this->overwriteAppService($claim->app);
        $receiver = new CommunicationReceivers()->find($claim->app, $claim->company, MetaWhatsAppHandler::slug());

        return $receiver === null
            ? response()->json(['message' => 'Receiver not found'], 404)
            : $this->store($receiver->uuid, $request);
    }
}
