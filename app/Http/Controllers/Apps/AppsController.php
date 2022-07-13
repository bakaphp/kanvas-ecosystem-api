<?php

declare(strict_types=1);

namespace Kanvas\Http\Controllers\Apps;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kanvas\Apps\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\Apps\Actions\UpdateAppsAction;
use Kanvas\Apps\Apps\DataTransferObject\AppsPostData;
use Kanvas\Apps\Apps\DataTransferObject\AppsPutData;
use Kanvas\Apps\Apps\DataTransferObject\CollectionResponseData;
use Kanvas\Apps\Apps\DataTransferObject\SingleResponseData;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Enums\HttpDefaults;
use Kanvas\Http\Controllers\BaseController;
use Kanvas\Users\Users\Models\Users;

class AppsController extends BaseController
{
    /**
     * DI User.
     */
    protected Users $user;

    /**
     * Create a new controller instance.
     *
     * @param  UserRepository  $users
     *
     * @return void
     */
    public function __construct(Users $user)
    {
        $this->user = $user;
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     *
     * @todo Need to move this pagination somewhere else.
     */
    public function index() : JsonResponse
    {
        $limit = HttpDefaults::RECORDS_PER_PAGE;
        $results = Apps::paginate($limit->getValue());
        $collection = CollectionResponseData::fromModelCollection($results);

        return response()->json($collection->formatResponse());
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     */
    public function show(int $id) : JsonResponse
    {
        $app = Apps::findOrFail($id); // Query should be done before passing to dto ?
        $response = SingleResponseData::fromModel($app);
        return response()->json($response);
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     */
    public function create(Request $request) : JsonResponse
    {
        $data = AppsPostData::fromRequest($request);
        $app = new CreateAppsAction($data);
        return response()->json($app->execute());
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     */
    public function update(Request $request, int $id) : JsonResponse
    {
        $data = AppsPutData::fromRequest($request);
        $app = new UpdateAppsAction($data);
        return response()->json($app->execute($id));
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     */
    public function destroy(int $id) : JsonResponse
    {
        Apps::findOrFail($id)->delete();
        return response()->json('Succesfully Deleted');
    }
}
