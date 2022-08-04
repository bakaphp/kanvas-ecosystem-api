<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kanvas\Companies\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\Companies\Actions\UpdateCompaniesAction;
use Kanvas\Companies\Companies\DataTransferObject\CollectionResponseData;
use Kanvas\Companies\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\Companies\DataTransferObject\CompaniesPutData;
use Kanvas\Companies\Companies\DataTransferObject\SingleResponseData;
use Kanvas\Traits\FilesystemAttachTrait;
use Kanvas\Companies\Companies\Models\Companies;
use Kanvas\Enums\HttpDefaults;
use Kanvas\Users\Users\Models\Users;

class CompaniesController extends BaseController
{
    use FilesystemAttachTrait;

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
        $results = Companies::paginate($limit->getValue());
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
        $company = Companies::findOrFail($id);
        $response = SingleResponseData::fromModel($company);
        return response()->json($response);
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     */
    public function create(Request $request) : JsonResponse
    {
        $data = CompaniesPostData::fromRequest($request);
        $company = new CreateCompaniesAction($data);
        $company = $company->execute();
        $response = SingleResponseData::fromModel($company);
        return response()->json($response);
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     */
    public function update(Request $request, int $id) : JsonResponse
    {
        $data = CompaniesPutData::fromRequest($request);
        $company = new UpdateCompaniesAction($data);
        $company = $company->execute($id);
        $this->setAssociatedModule($company);
        $this->associateFileSystem();
        $response = SingleResponseData::fromModel($company);
        return response()->json($response);
    }

    /**
     * Fetch all apps.
     *
     * @return JsonResponse
     */
    public function destroy(int $id) : JsonResponse
    {
        Companies::findOrFail($id)->delete();
        return response()->json('Succesfully Deleted');
    }
}
