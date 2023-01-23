<?php
declare(strict_types=1);

namespace Kanvas\CustomFields\Traits;

use Illuminate\Database\Eloquent\Model;
use Kanvas\CustomFields\Interfaces\CustomFieldModelInterface;

/**
 * Custom field class.
 */
trait HasCustomFieldsObserver
{
    /**
     * After Create.
     *
     * @param Model $model
     *
     * @return void
     */
    public function created(CustomFieldModelInterface $model)
    {
        $model->saveCustomFields();
    }

    /**
     * After updated.
     *
     * @param Model $model
     *
     * @return void
     */
    public function updated(CustomFieldModelInterface $model)
    {
        if (!empty($this->customFields)) {
            $model->deleteAllCustomFields();
            $model->saveCustomFields();
        }
    }

    /**
     * After Delete.
     *
     * @param Model $model
     *
     * @return void
     */
    public function deleted(CustomFieldModelInterface $model)
    {
        $model->deleteAllCustomFields();
    }
}
