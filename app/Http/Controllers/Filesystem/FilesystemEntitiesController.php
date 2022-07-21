<?php

declare(strict_types=1);

namespace App\Http\Controllers\Filesystem;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kanvas\Filesystem\FilesystemEntities\Actions\CreateFilesystemEntitiesAction;
use Kanvas\Filesystem\FilesystemEntities\Actions\UpdateFilesystemEntitiesAction;
use Kanvas\Filesystem\FilesystemEntities\DataTransferObject\FilesystemEntitiesPostData;
use Kanvas\Filesystem\FilesystemEntities\DataTransferObject\FilesystemEntitiesPutData;
use Kanvas\Filesystem\FilesystemEntities\DataTransferObject\CollectionResponseData;
use Kanvas\Filesystem\FilesystemEntities\DataTransferObject\SingleResponseData;
use Kanvas\Filesystem\FilesystemEntities\Models\FilesystemEntities;
use Kanvas\Enums\HttpDefaults;
use Kanvas\Users\Users\Models\Users;

class FilesystemEntitiesController extends BaseController
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
     * Fetch all
     *
     * @return JsonResponse
     *
     * @todo Need to move this pagination somewhere else.
     */
    public function index() : JsonResponse
    {
        $limit = HttpDefaults::RECORDS_PER_PAGE;
        $results = FilesystemEntities::paginate($limit->getValue());
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
        $app = FilesystemEntities::findOrFail($id); // Query should be done before passing to dto ?
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
        $data = FilesystemEntitiesPostData::fromRequest($request);
        $app = new CreateFilesystemEntitiesAction($data);
        return response()->json($app->execute());
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     */
    public function update(Request $request, int $id) : JsonResponse
    {
        $data = FilesystemEntitiesPutData::fromRequest($request);
        $app = new UpdateFilesystemEntitiesAction($data);
        return response()->json($app->execute($id));
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     */
    public function destroy(int $id) : JsonResponse
    {
        FilesystemEntities::findOrFail($id)->delete();
        return response()->json('Succesfully Deleted');
    }
}
