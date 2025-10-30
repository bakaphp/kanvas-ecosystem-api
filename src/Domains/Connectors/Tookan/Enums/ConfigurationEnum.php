<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\Enums;

enum ConfigurationEnum: string
{
    case API_KEY = 'TOOKAN_API_KEY';
    case BASE_URL = 'TOOKAN_BASE_URL';
    case SANDBOX_URL = 'https://api.tookanapp.com';

    // API Endpoints
    case CREATE_TASK_PATH = '/v2/create_task';
    case UPDATE_TASK_PATH = '/v2/update_task';
    case GET_TASK_DETAILS_PATH = '/v2/get_task_details';
    case GET_JOB_DETAILS_PATH = '/v2/get_job_details';
    case DELETE_TASK_PATH = '/v2/delete_task';
    case GET_AGENT_PATH = '/v2/get_available_agents';
    case ASSIGN_AGENT_PATH = '/v2/assign_agent';
    case GET_ALL_TASKS_PATH = '/v2/get_all_tasks';
    case GET_ALL_FLEETS_PATH = '/v2/get_all_fleets';
    case CREATE_MERCHANT_PATH = '/v2/add_merchant';
    case UPDATE_MERCHANT_PATH = '/v2/edit_merchant';
    case DELETE_MERCHANT_PATH = '/v2/delete_merchant';
    case GET_MERCHANT_PATH = '/v2/view_merchant';
}
