INSERT INTO integrations (name, uuid, apps_id, config, handler, type, actions_id, receivers_id, is_deleted, created_at, updated_at)
VALUES ('ebay', UUID(), 0, '{"client_id": {"type": "text", "required": true}, "client_secret": {"type": "text", "required": true}}', 'Kanvas\\Connectors\\Ebay\\Handlers\\EbayHandler', 'oauth', NULL, NULL, 0, NOW(), NOW());
