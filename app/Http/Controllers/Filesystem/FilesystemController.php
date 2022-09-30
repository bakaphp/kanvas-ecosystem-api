<?php

declare(strict_types=1);

namespace App\Http\Controllers\Filesystem;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kanvas\Enums\HttpDefaults;
use Kanvas\Filesystem\Filesystem\Actions\UploadFileAction;
use Kanvas\Filesystem\Filesystem\Actions\CreateFilesystemAction;
use Kanvas\Filesystem\Filesystem\DataTransferObject\CollectionResponseData;
use Kanvas\Filesystem\Filesystem\DataTransferObject\FilesystemPostData;
use Kanvas\Filesystem\Filesystem\DataTransferObject\SingleResponseData;
use Kanvas\Filesystem\Filesystem\Models\Filesystem;
use Kanvas\UsersGroup\Users\Models\Users;

class FilesystemController extends BaseController
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
     */
    public function show(int $id) : JsonResponse
    {
        $app = Filesystem::findOrFail($id);
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
        $data = FilesystemPostData::fromRequest($request);
        $uploadedFile = new UploadFileAction($data->file);
        $createFilesystem = new CreateFilesystemAction($uploadedFile->execute());
        $response = SingleResponseData::fromModel($createFilesystem->execute());

        return response()->json($response);
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     */
    public function destroy(int $id) : JsonResponse
    {
        Filesystem::findOrFail($id)->delete();
        return response()->json('Succesfully Deleted');
    }
}
