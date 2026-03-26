<?php

return [
    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * When the clean-command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     */
    'clean_after_days' => 365,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject returns soft deleted models.
     */
    'include_soft_deleted_subjects' => false,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     */
    'activity_model' => Kanvas\Activities\Models\Activity::class,

    /*
     * These are the action classes used by the package.
     * You can swap them out with your own implementations.
     */
    'actions' => [
        'log_activity' => Spatie\Activitylog\Actions\LogActivityAction::class,
        'clean_log' => Spatie\Activitylog\Actions\CleanActivityLogAction::class,
    ],

    /*
     * Attributes that should be excluded from logging for all models.
     */
    'default_except_attributes' => [],
];
