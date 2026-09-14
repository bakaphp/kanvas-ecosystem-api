<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'Intelligence';
    case GEMINI_KEY = 'kanvas-intelligence-gemini-key';
    case GEMINI_MODEL = 'kanvas-intelligence-gemini-model';
    case AI_PROVIDER = 'kanvas-intelligence-ai-provider';
    case AI_PROVIDER_BASE_URI = 'kanvas-intelligence-ai-provider-base-uri';
    case AI_PROVIDER_KEY = 'kanvas-intelligence-ai-provider-key';
    case AI_PROVIDER_MODEL = 'kanvas-intelligence-ai-provider-model';
    case OPEN_AI_EMBEDDINGS_KEY = 'kanvas-intelligence-openai-embeddings-key';
    case OPEN_AI_EMBEDDINGS_MODEL = 'kanvas-intelligence-openai-embeddings-model';
    case PINECONE_API_KEY = 'kanvas-intelligence-pinecone-api-key';
    case PINECONE_INDEX_URL = 'kanvas-intelligence-pinecone-index-url';
    // External voice runtime (Pipecat / Cloud Run): base URL + the runtime's
    // RUNTIME_API_TOKEN, used to trigger outbound/test calls via POST /outbound.
    case VOICE_RUNTIME_URL = 'kanvas-intelligence-voice-runtime-url';
    case VOICE_RUNTIME_API_TOKEN = 'kanvas-intelligence-voice-runtime-api-token';
    case ADK_BASE_URL = 'google_orchestrator_base_url';
    case ADK_API_KEY = 'google_orchestrator_api_key';
    case ADK_APP_NAME = 'google_orchestrator_app_name';
    case ADK_AI_ASSIST_APP_NAME = 'google_orchestrator_ai_assist_app_name';
    case ADK_AI_ASSIST_BASE_URL = 'google_orchestrator_ai_assist_base_url';
    case AI_ASSIST_ENABLED = 'sales_assist_ai_assist_enabled';
    case AI_ASSIST_GREETING_MSG = 'ai_assist_greeting_msg';
    case AGENT_HAND_OFF = 'agent_hand_off';
    case AGENT_HAND_OFF_TYPE = 'agent_hand_off_type';
    case AGENT_CHANNEL_TYPE = 'agent_channel_type';
    case LEAD_CONTEXT_INFO = 'lead_ai_agent_context_info';
    case LAST_MESSAGE_TIME = 'last_message_time';
    case LAST_MESSAGE = 'last_message';
    case MUTE_AI_AGENT = 'ai_control';
    case AI_AGENT_USER_ID = 'ai-agent-user-id';
    case FIRST_MESSAGE_ONLY_DURING_BUSINESS_HOURS = 'ai_agent_first_message_only_during_business_hours';
    case FIRST_MESSAGE_ONLY_DURING_OFF_BUSINESS_HOURS = 'ai_agent_first_message_only_during_off_business_hours';
    case AI_ENGAGEMENT_MESSAGE_ONLY_ONE_NOTIFICATION = 'ai_engagement_message_only_one_notification';
    case AI_MODE = 'ai_mode';
    case SUPPORT_MODE_DELAYED_RESPONSE = 'ai_agent_support_mode_delayed_response';
    case AGENT_AI_MODE = 'agent_ai_mode';
    case AI_ENABLE = 'ai';
    case NOTIFICATION_CHANNELS = 'notification_enabled_channels';
    case FIRST_ENGAGEMENT_NOTIFICATION_CHANNELS = 'first_engagement_notification_channels';
    case ENGAGEMENT_NOTIFICATION_CHANNELS = 'engagement_notification_channels';
    // When truthy on an app, that app's key may resolve voice agents across
    // apps (voiceAgentSpec by uuid, ignoring apps_id). Enable ONLY on the
    // trusted voice-runtime app; every other app-key stays app-scoped.
    case VOICE_RUNTIME_CROSS_APP = 'kanvas-intelligence-voice-runtime-cross-app';
}
