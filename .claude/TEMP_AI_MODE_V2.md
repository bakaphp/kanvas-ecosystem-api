# TEMPORAL: Feature flag Intelligence AI Mode V2

> **Este es un cambio temporal.** En una sesión futura, cuando el usuario diga "deja solo la v2", seguir las instrucciones de limpieza al final de este documento.

## Qué hace el flag

App setting key: `intelligence_lead_type_mode_v2` (boolean)

- **Flag OFF (v1)** → comportamiento de la rama `1.x`: claves genéricas (`ai_mode`, `ai_follow_up`), lógica simple de check de modo, cache para mensajes sin respuesta, follow-up basado en `FollowUpTypeEnum`.
- **Flag ON (v2)** → comportamiento de esta rama: claves por tipo de lead, check de horarios laborales en el responder, sin cache, follow-up basado en `FollowUpValueEnum` (ON/OFF).

Activar: `$app->set('intelligence_lead_type_mode_v2', true)`

## Arquitectura: archivos V1 vs V2

### Archivos V1 (lógica de 1.x, solo usados cuando flag=OFF)

| Archivo V1 | Equivalente V2 |
|---|---|
| `Triggers/Actions/ApplyLeadAiModeV1Action.php` | `ApplyLeadAiModeAction.php` |
| `Jobs/SendUnrespondedAgentMessageV1Job.php` | `SendUnrespondedAgentMessageJob.php` |
| `PipelinesStages/Actions/FollowUpEngagementV1Action.php` | `FollowUpEngagementAction.php` |
| `Support/UnrespondedLeadAgentMessageCache.php` | *(eliminado en v2, no existe equivalente)* |

### Archivos que usan inline `isV2Enabled()` (no se pudo separar limpiamente)

| Archivo | Por qué inline |
|---|---|
| `Services/LeadConfigurationService.php` | Dispatcher central, controla qué claves usar |
| `Agents/Actions/BaseAgentResponderAction.php` | Clase base — no se puede swappear sin duplicar subclases |
| `Agents/Traits/HandlesSupportModeDelayedResponseTrait.php` | Trait — PHP no permite selección dinámica de traits |
| `Triggers/Workflows/MessageHumanTakeoverTriggerActivity.php` | Actividad Temporal, difícil de swappear en runtime |

### Callers que hacen dispatch v1 o v2

| Caller | Dispatch |
|---|---|
| `Triggers/Workflows/TriggerIntelligenceActivity.php` | `ApplyLeadAiModeAction` vs `ApplyLeadAiModeV1Action` |
| `Agents/Traits/HandlesSupportModeDelayedResponseTrait.php` | `SendUnrespondedAgentMessageJob` vs `SendUnrespondedAgentMessageV1Job` |
| `app/Console/Commands/Intelligence/FollowUpEngagementCommand.php` | `FollowUpEngagementAction` vs `FollowUpEngagementV1Action` |

## Cambios de 1.x traídos directamente (sin flag)

Estos cambios de `1.x` se aplicaron directamente, no necesitan flag:

| Archivo | Cambio |
|---|---|
| `Guild/Leads/Models/LeadType.php` | Cast `'array'` en lugar de `Baka\Casts\Json` |
| `Intelligence/Enums/ConfigurationEnum.php` | +`ADK_AI_ASSIST_APP_NAME`, +`ADK_AI_ASSIST_BASE_URL` |
| `Intelligence/Agents/Services/GoogleADKService.php` | Parámetro `$baseUrlOverride` |
| `Intelligence/Sessions/Services/SessionChannelService.php` | Canal `ai-assist` |
| `Intelligence/Sessions/Actions/CreateContentSessionAction.php` | `LeadsRepository::getPeopleActiveLead()` para el mapeo de leads |

## Qué hacer cuando se pida "dejar solo la v2"

1. **Eliminar archivos V1:**
   - `Triggers/Actions/ApplyLeadAiModeV1Action.php`
   - `Jobs/SendUnrespondedAgentMessageV1Job.php`
   - `PipelinesStages/Actions/FollowUpEngagementV1Action.php`
   - `Support/UnrespondedLeadAgentMessageCache.php`

2. **Limpiar `LeadConfigurationService.php`:**
   - Eliminar `isV2Enabled()` y sus imports (`Apps`, `IntelligenceModeEnum`)
   - En `getAiModeKey()`: eliminar `if (! self::isV2Enabled()) { return 'ai_mode'; }`
   - En `getFollowUpModeKey()`: eliminar `if (! self::isV2Enabled()) { return ... }`

3. **`BaseAgentResponderAction.php`:** quitar `&& LeadConfigurationService::isV2Enabled()` de la condición.

4. **`HandlesSupportModeDelayedResponseTrait.php`:** eliminar todos los bloques `if (! LeadConfigurationService::isV2Enabled())`, quitar imports de `SendUnrespondedAgentMessageV1Job` y `UnrespondedLeadAgentMessageCache`.

5. **`MessageHumanTakeoverTriggerActivity.php`:** eliminar el bloque `if (! LeadConfigurationService::isV2Enabled()) { ... UnrespondedLeadAgentMessageCache::clear ... }`, quitar sus imports.

6. **`TriggerIntelligenceActivity.php`:** reemplazar el `$actionClass = isV2Enabled() ? ... : ...` por `new ApplyLeadAiModeAction($lead, $triggerType)->execute()` directo.

7. **`FollowUpEngagementCommand.php`:** reemplazar el `$followUpClass = isV2Enabled() ? ... : ...` por `new FollowUpEngagementAction($lead, $log)->execute()` directo. Eliminar imports de `FollowUpEngagementV1Action` y `LeadConfigurationService`.

8. **Eliminar este archivo `.claude/TEMP_AI_MODE_V2.md`.**
