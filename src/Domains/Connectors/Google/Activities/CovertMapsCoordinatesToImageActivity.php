<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Activities;

use Baka\Contracts\AppInterface;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Kanvas\Connectors\Google\Services\MapStaticApiService;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\Actions\CreateAttributeType;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributeDto;
use Kanvas\Inventory\Attributes\DataTransferObject\AttributesType;
use Kanvas\Workflow\KanvasActivity;

class CovertMapsCoordinatesToImageActivity extends KanvasActivity
{
    public function execute(Model $entity, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        $mapCoordinates = json_decode($entity->getAttributeBySlug('coordinates')->attribute->value, true);

        if (empty($mapCoordinates)) {
            return [
                'result'     => false,
                'message'    => 'Coordinates not found on message body',
                'activity'   => self::class,
                'message_id' => $entity->getId(),
            ];
        }
        $latitude = $mapCoordinates['lat'];
        $longitude = $mapCoordinates['long'];
        $tempFilePath = MapStaticApiService::getImageFromCoordinates($latitude, $longitude);
        $fileName = basename($tempFilePath);

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tempFilePath);

        $uploadedFile = new UploadedFile(
            $tempFilePath,
            $fileName,
            $mimeType,
            null,
            true
        );

        $filesystem = new FilesystemServices($app);
        $fileSystemRecord = $filesystem->upload($uploadedFile, $entity->user);

        try {
            //Create image atrribute type
            $imageAttributeType = new CreateAttributeType(
                AttributesType::viaRequest([
                    'company_id' => $entity->companies_id,
                    'name'       => 'image',
                    'is_default' => false,
                ], $entity->user),
                $entity->user
            );

            //Create attribute
            $imageAttribute = (new CreateAttribute(
                AttributeDto::viaRequest(
                    [
                        'company'       => $entity->company,
                        'app'           => $app,
                        'name'          => 'Image',
                        'slug'          => 'image',
                        'attributeType' => $imageAttributeType,
                        'isVisible'     => true,
                        'isSearchable'  => true,
                        'isFiltrable'   => true,
                    ],
                    $entity->user,
                    $app
                ),
                $entity->user
            ))->execute();

            $imageAttribute->addDefaultValue($fileSystemRecord->url);
        } catch (\Throwable $th) {
            return [
                'result'    => false,
                'message'   => 'Failed to save image attribute',
                'activity'  => self::class,
                'entity_id' => $entity->getId(),
                'error'     => $th->getMessage(),
            ];
        }

        // Clean up the temporary file
        unlink($tempFilePath);

        return [
            'result'     => true,
            'message'    => 'Image Url converted to Kanvas Filesystem',
            'activity'   => self::class,
            'data'       => $fileSystemRecord,
            'message_id' => $entity->getId(),
        ];
    }
}
