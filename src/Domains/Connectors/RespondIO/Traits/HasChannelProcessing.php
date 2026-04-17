<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;

trait HasChannelProcessing
{
    protected function getOrCreateChannel(
        AppInterface $app,
        CompanyInterface $company,
        string $identifier,
        ?string $name = null,
        ?Lead $lead = null
    ): Channel {
        $cleanedPhone = Str::normalizePhoneNumber($identifier);
        $slug = 'respondio-' . $cleanedPhone;

        return DB::transaction(function () use ($app, $company, $slug, $name, $identifier, $lead): Channel {
            $channel = Channel::where('slug', $slug)
                ->where('companies_id', $company->getId())
                ->where('apps_id', $app->getId())
                ->lockForUpdate()
                ->first();

            if (! $channel) {
                $channel = new Channel();
                $channel->name = $name ?? 'RespondIO Chat: ' . $identifier;
                $channel->description = 'RespondIO Chat: ' . $identifier;
                $channel->slug = $slug;
                $channel->companies_id = $company->getId();
                $channel->apps_id = $app->getId();

                if ($lead) {
                    $channel->entity_namespace = get_class($lead);
                    $channel->entity_id = $lead->getId();
                }

                $channel->save();
            } elseif ($name !== null && $channel->name !== $name) {
                $channel->name = $name;
                $channel->save();
            }

            if ($lead && ($channel->entity_namespace === null || $channel->entity_namespace === '')) {
                $channel->entity_namespace = get_class($lead->people);
                $channel->entity_id = $lead->people->getId();
                $channel->update();
            }

            if ($channel->id) {
                $channel->set(
                    ConfigurationEnum::AGENT_CHANNEL_TYPE->value,
                    'RespondIO'
                );
            }

            return $channel;
        }, 5);
    }

    protected function findChannelByIdentifier(
        AppInterface $app,
        CompanyInterface $company,
        string $identifier
    ): ?Channel {
        $cleanedPhone = Str::normalizePhoneNumber($identifier);
        $slug = 'respondio-' . $cleanedPhone;

        return Channel::where('slug', $slug)
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->first();
    }

    protected function findLeadFromChannel(
        AppInterface $app,
        CompanyInterface $company,
        Channel $channel
    ): ?Lead {
        if (! $channel->entity_id) {
            return null;
        }

        return Lead::where('id', $channel->entity_id)
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->first();
    }
}
