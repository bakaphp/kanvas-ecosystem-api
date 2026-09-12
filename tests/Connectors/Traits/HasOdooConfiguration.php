<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Odoo\Client;
use Kanvas\Connectors\Odoo\Enums\ConfigurationEnum;

/**
 * Fakes Odoo's JSON-RPC endpoint via Laravel's `Http` facade, so tests exercise the real
 * `Client`/`OdooApiClient` request-building path without hitting a real Odoo instance. Unlike
 * Salesforce (one URL per sobject/verb), every Odoo call goes to the same `/jsonrpc` endpoint, so
 * the fake dispatches on the JSON-RPC body's `method`/`args` instead of the URL.
 */
trait HasOdooConfiguration
{
    // A resolvable public host: SafeUrl does a real DNS lookup before the faked request lands.
    protected const string ODOO_URL = 'https://example.com';
    protected const string ODOO_DATABASE = 'test_db';
    protected const int ODOO_UID = 7;

    protected function configureOdoo(CompanyInterface $company, AppInterface $app): void
    {
        $company->set(ConfigurationEnum::URL->value, self::ODOO_URL);
        $company->set(ConfigurationEnum::DATABASE->value, self::ODOO_DATABASE);
        $company->set(ConfigurationEnum::USERNAME->value, 'test@example.com');
        $company->set(ConfigurationEnum::API_KEY->value, 'test-api-key');

        Client::forgetUid($app, $company);
    }

    /**
     * @param array<string, mixed> $results keyed by "{odooMethod}:{model}" (e.g. "create:res.partner"),
     *                                       value is the JSON-RPC `result` to return — a literal, or a
     *                                       callable(array $args, array $kwargs): mixed for dynamic ones
     */
    protected function fakeOdooApi(array $results = []): void
    {
        Http::fake([
            self::ODOO_URL . '/jsonrpc' => function ($request) use ($results) {
                $params = $request['params'];

                if ($params['method'] === 'authenticate') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => self::ODOO_UID]);
                }

                // execute_kw args: [db, uid, apiKey, model, method, args, kwargs]
                [, , , $model, $odooMethod, $args, $kwargs] = $params['args'];
                $key = $odooMethod . ':' . $model;
                $result = $results[$key] ?? null;

                if (is_callable($result)) {
                    $result = $result($args, $kwargs);
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);
            },
        ]);
    }
}
