<?php

declare(strict_types=1);

namespace Kanvas\Templates\Models;

use Baka\Traits\DynamicSearchableTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Support\LocationContext;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Models\BaseModel;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Templates\Enums\TemplateEngineEnum;
use Kanvas\Templates\Support\TemplateSandbox;
use Kanvas\TemplatesVariables\Models\TemplatesVariables;
use Override;

/**
 * Apps Model.
 *
 * @property int $id
 * @property int $users_id
 * @property int $companies_id
 * @property int $apps_id
 * @property string $name
 * @property ?string $subject
 * @property ?string $title
 * @property int $parent_template_id
 * @property string $template
 * @property TemplateEngineEnum $engine
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property int $is_deleted
 */
class Templates extends BaseModel
{
    use HasCustomFields;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }

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

    #[Override]
    protected function casts(): array
    {
        return [
            'engine' => TemplateEngineEnum::class,
        ];
    }

    #[Override]
    protected static function booted(): void
    {
        static::saving(fn (self $template) => $template->assertWritable());
        static::deleting(fn (self $template) => $template->assertNotBlade());
    }

    public function isBlade(): bool
    {
        return $this->engine === TemplateEngineEnum::BLADE;
    }

    /**
     * A blade row is changed only by hand in the database; through Eloquent it can be neither
     * created, edited nor deleted. A twig row is compiled in the sandbox before it is stored, so a
     * template the sandbox refuses never reaches a render.
     */
    protected function assertWritable(): void
    {
        if (! $this->exists) {
            $this->engine ??= TemplateEngineEnum::TWIG;
        }

        $this->assertNotBlade();

        if ($this->engine === TemplateEngineEnum::BLADE) {
            throw new ValidationException('Templates can only be created as twig.');
        }

        foreach (['template', 'subject'] as $field) {
            if (! $this->exists || $this->isDirty($field)) {
                TemplateSandbox::validate((string) $this->{$field});
            }
        }

        if ($this->isDirty('parent_template_id') && $this->parent_template_id > 0) {
            $this->assertParentIsVisible();
        }
    }

    public function assertNotBlade(): void
    {
        if ($this->exists && $this->getRawOriginal('engine') === TemplateEngineEnum::BLADE->value) {
            throw new ValidationException("Template {$this->name} is managed by the platform and cannot be changed here.");
        }
    }

    protected function assertParentIsVisible(): void
    {
        $visible = self::query()
            ->notDeleted()
            ->whereKey($this->parent_template_id)
            ->whereIn('apps_id', [AppEnums::LEGACY_APP_ID->getValue(), $this->apps_id])
            ->whereIn('companies_id', [AppEnums::GLOBAL_COMPANY_ID->getValue(), $this->companies_id])
            ->exists();

        if (! $visible) {
            throw new ValidationException('Parent template not found.');
        }
    }

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

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => "Kanvas\Templates\Models\Templates::{$this->id}",
            'id' => (string) $this->id,
            'name' => $this->name,
            'subject' => $this->subject ?? '',
            'title' => $this->title ?? '',
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'users_id' => $this->users_id,
            'is_deleted' => (bool) $this->is_deleted,
            'created_at' => $this->created_at->timestamp,
            'updated_at' => $this->updated_at->timestamp,
        ];
    }

    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => 'objectID',
                    'type' => 'string',
                ],
                [
                    'name' => 'id',
                    'type' => 'string',
                ],
                [
                    'name' => 'name',
                    'type' => 'string',
                    'sort' => true,
                ],
                [
                    'name' => 'subject',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'title',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'companies_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'users_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'is_deleted',
                    'type' => 'bool',
                    'facet' => true,
                ],
                [
                    'name' => 'created_at',
                    'type' => 'int64',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'updated_at',
                    'type' => 'int64',
                    'optional' => true,
                    'sort' => true,
                ],
            ],
        ];
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_templates_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'templates');
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        $locationCompany = $user instanceof UserInterface ? LocationContext::readableCompany(static::class, $user) : null;

        if ($locationCompany !== null) {
            $query->where('companies_id', $locationCompany->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        if ($query->model->isTypesense()) {
            $query->options([
                'query_by' => 'name,subject,title',
            ]);
        }

        return $query;
    }
}
