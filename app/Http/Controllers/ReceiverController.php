<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Actions\SyncZohoAgentAction;
use Kanvas\Connectors\Zoho\Actions\SyncZohoLeadAction;
use Kanvas\Connectors\Zoho\Workflows\ZohoLeadOwnerWorkflow;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Workflow\WorkflowStub;

class ReceiverController extends BaseController
{
    /**
     * Receiver system based on UUID
     *
     * @throws BindingResolutionException
     */
    public function store(string $uuid, Request $request): JsonResponse
    {
        $app = app(Apps::class);
        $receiver = LeadReceiver::fromApp($app)->where('uuid', $uuid)->first();

        if (! $receiver) {
            return response()->json(['message' => 'Receiver not found'], 404);
        }

        $tempSubSystem = $uuid == $app->get('subsystem-temp-uuid');
        $zohoLeadTempSubSystem = $uuid == $app->get('zoho-lead-temp-uuid');
        $isTempSystem = $tempSubSystem || $zohoLeadTempSubSystem;

        Auth::loginUsingId($receiver->users_id);

        /**
         * @todo
         * This has to be a system where based on the receiver,
         * the receiver will determine the system module and action it will execute.
         * Then it will evaluate the data and send it to the queue to process.
         */

        //validate the request entity_id
        $request->validate([
            'entity_id' => 'required',
        ]);

        $leadExternalId = $request->get('entity_id');

        if ($receiver->rotation === null && ! $isTempSystem) {
            return response()->json(['message' => 'Rotation not found'], 404);
        }

        //temp solution until subsystem
        if ($tempSubSystem) {
            $syncZohoAgent = new SyncZohoAgentAction($app, $receiver->company, $request->get('email'));
            $syncZohoAgent->execute();

            return response()->json(['message' => 'Receiver processed']);
        }

        if ($zohoLeadTempSubSystem) {
            $syncLead = new SyncZohoLeadAction($app, $receiver->company, $leadExternalId);
            $syncLead->execute();

            return response()->json(['message' => 'Receiver processed']);
        }

        $workflow = WorkflowStub::make(ZohoLeadOwnerWorkflow::class);
        $workflow->start($leadExternalId, $receiver, app(Apps::class), []);

        return response()->json(['message' => 'Receiver processed']);
    }
}
