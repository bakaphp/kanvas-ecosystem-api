<?php

declare(strict_types=1);

namespace Kanvas\Templates\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Companies\Models\Companies;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Models\BaseModel;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\TemplatesVariables\Models\TemplatesVariables;

/**
 * Apps Model.
 *
 * @property int $id
 * @property int $users_id
 * @property int $companies_id
 * @property int $apps_id
 * @property string $name
 * @property int $parent_template_id
 * @property string $template
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class Templates extends BaseModel
{
    use HasCustomFields;
    // use Cachable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'email_templates';

    /**
     * The attributes that should not be mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    public function addParentTemplate(Templates $template): void
    {
        $this->parent_template_id = $template->id;
        $this->saveOrFail();
    }

    /**
     * Template I'm based from
     *
     * @return BelongsTo <Templates>
     */
    public function parentTemplate(): BelongsTo
    {
        return $this->belongsTo(Templates::class, 'parent_template_id');
    }

    /**
     * Check if the template has a parent template
     */
    public function hasParentTemplate(): bool
    {
        return $this->parent_template_id > 0;
    }

    /**
     * NotificationTypes Relationship
     */
    public function notificationType(): HasOne
    {
        return $this->hasOne(NotificationTypes::class, 'template_id');
    }

    /**
     * NotificationTypes Relationship
     */
    public function templateVariables(): HasMany
    {
        return $this->hasMany(TemplatesVariables::class, 'template_id');
    }

    /**
     * User I belong to
     *
     * @return BelongsTo <Users>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Company I belong to
     *
     * @return BelongsTo <Companies>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }
}
