# Changelog

All notable changes to the Kanvas Ecosystem API are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This repository does not publish tagged releases — `development` is the rolling
integration branch and `1.x` is the deployed line — so entries are grouped by the
month the work landed instead of by version number.

## Keeping this file up to date

- Add a bullet under the current month for anything that changes behaviour for API
  consumers: new queries/mutations, new connectors or agent tools, behaviour changes,
  and bug fixes that were visible to users.
- Use the section that matches the commit type: `feat` → **Added**, `fix`/`hotfix` →
  **Fixed**, `refact`/`refactor`/`chore`/`perf` → **Changed**, `build`/`ci` →
  **Build & CI**, `docs` → **Documentation**.
- Prefix the bullet with the scope in bold when the commit had one, e.g.
  `- **souk**: Exclude configurable states from order turnover`.
- Skip pure internal churn (formatting, test-only commits, work-in-progress commits).
  Dependency bumps are summarised per month rather than listed one by one.

The history below was reconstructed from the commit log; entries keep the wording of
the commit that introduced them.

## August 2026

### Added

- New export tool
- Add upload tools
- Add upload files tools
- Upload files tools, search tools, bulk update tools
- Add payment status and amount snapshots to order transitions
- **voice**: Gated cross-app agent resolution for the voice runtime
- Agent mailgun email
- Mark invoice emails as read once fully processed
- Add a default invoice-tracking sheet so Apex/Arc log invoices automatically
- Filter by slug
- **voice**: SendVoiceAgentTestCall mutation to place a test call
- Optionally privatize conversation messages
- Expose default event configuration
- Add extract_invoice_data tool bridging Gmail attachments to Kanvas's PDF classifier
- Add event configuration agent tool
- **event**: Dashboard stats for occupancy, no-shows and players
- **event**: Expose the time slot <-> booking relation in GraphQL
- Add Gmail read/download tool for Apex and Arc (Task #277)
- **agent**: Accept voice_config on create/update so the admin UI can save it
- Add create_google_sheet_tab tool for Apex and Arc
- Add clear_google_sheet_range tool for Apex and Arc
- Add Google Sheets read/write/update tool for Apex and Arc
- Add company brain agent type
- **insurance**: Platform-scoped credentials, product seeding and catalog cache
- **intelligence**: Extend Neuron handoff tool
- **intelligence**: Add Laravel handoff lead tool
- **movipass**: Add the InsuranceClient role and grant it its ability
- Support standalone AR credit memos with per-line GL account routing
- Recognize .xlsx/.xls/.csv when attaching a file to a bill
- Recognize .csv when attaching a file to an invoice/credit memo
- Add AR credit memo creation, and note/file tools for invoices
- Sales manager agent
- Engagement slack notification
- Add note and file attachment tools to Apex (AP agent)
- **twilio**: Add conversation-aware carrier retry
- **twilio**: Track delivery attempts and consent
- **events**: Sync appointments to Google Calendar

### Fixed

- Slack reminder msg
- Normalize agent engagement payloads
- Use full Google Calendar scope
- Record privatize message workflow history
- Fix Gemini 400 crash from write_google_sheet's array parameter
- Google calendar test
- Retry calendar events without unsupported Meet
- Calculate LoCompro fees per order
- Retry nervous system tools
- Install the Gmail service definitions from google/apiclient-services
- Accept null engagement page data
- Align LoCompro service fee calculation
- Sales report agent
- **voice-spec**: From_number = Twilio setting, fall back to company.phone
- **voice-spec**: Read company phone from column, never 503 on missing company
- Store created follow-up message model
- Never leak raw exception detail into an agent's channel-facing reply
- Vin connection issues
- Agent crfation user
- **souk**: Derive orderStats snapshot from transition order, not ended_at
- **event**: Reject bookings on time slots that already started
- Validate Twilio sender routes before sending
- Create_ap_bill was losing the vendor's invoice number and could get the wrong subaccount
- Exclude cart conditions from tax threshold
- Make void_ap_bill idempotent against retries
- Keep Acumatica write sessions running past a client disconnect
- Surface Acumatica's nested per-field validation errors
- Create engagement pages as agent user
- Attribute engagements to agent user
- Report engagement page failures to Sentry
- Report Acumatica's real status on AP check payments
- Restore missing Invoice model import in CreateArInvoiceTool
- Issue with static
- **intelligence**: Require an explicit customer/vendor name in create_ar_invoice and create_ap_bill
- **intelligence**: Create_ar_invoice no longer auto-applies a cash receipt
- Stop reporting empty-input validation errors in ImportMutation to Sentry

### Changed

- Google tools gemini issue
- Add async agent chat
- **voice**: Move PlaceVoiceTestCallAction into Actions/Voice
- Vin notes + agent workflow
- Privatize messages through workflow activity
- Tasks agents / default model
- Use official Google Calendar client
- Simplify ReadEmailDetailsAction, document real credential-setup steps
- **event**: No-shows as a scope on the generic pass query
- Fix issue with retool
- Hotfix agents permissions
- Add org tools
- Index doc to agent
- Delete from rag
- Neuron dfult rag
- Laravel default config
- Rag v2 laravel incldued
- Add deal tools
- Clarify Neuron agent behavior contract
- Rename Neuron agent contract
- **insurance**: Make the insurance graph provider-agnostic
- Organize Neuron RAG classes
- Dedupe AR invoice/credit-memo Acumatica actions and tools
- Howtifx msg upload error
- Message type index
- Hotfix affialite links
- N+1 query issues
- Fix import order
- Generalize Neuron RAG knowledge pipeline
- Move tariff data to application resources
- Agent / human role description
- Agent job description
- Clean up comments
- Fix the coding agent test
- Fix coding tool loop
- Make Lead RAG opt-in per agent

### Documentation

- Log the Kanvas bill/invoice id in the sheet, not the vendor's own number
- Guide agents to chain download_attachment's url into attach_bill_file/attach_invoice_file
- Document formula support in write/update Google Sheet tools

### Dependencies

- Bumped 11 package(s) via Dependabot, including `docker/login-action`, `google/cloud-recommendations-ai`, `intervention/image`, `laravel/ai`, `laravel/boost`, `laravel/octane`, `neuron-core/neuron-ai`, `pusher/pusher-php-server` and others.

## July 2026

### Added

- Add Neuron lead RAG with Typesense
- **intelligence**: Give Arc a standalone apply_ar_payment tool
- **intelligence**: Give Apex a standalone apply_ap_payment tool
- **intelligence**: Add engagement page tool
- Fix commission report
- Track Twilio message status callbacks
- Validate lead phones before messaging
- **intelligence**: Add social message tools
- **souk**: Make the order correction final-status guard toggleable per app
- **intelligence**: Add lead SMS tool
- Add search template
- **intelligence**: Support Neuron sub-agent tools
- Add agent provider config
- New slack / email tools
- Add get message by id
- Dedup duplicate best-seller listings + one variant per product in bundles
- **intelligence**: Give Arc (AR agent) staging-only invoice+receipt create/void tools
- **intelligence**: Give Apex (AP agent) staging-only bill create/void tools
- **acumatica**: Staging-gated write-back for AP bills and AR invoices/payments
- Add agent heartbeat
- Add voiceAgentSpec query for the external voice runtime
- Scrapingdog best sellers scraper (mirror of scraperapi)
- Start project module
- **reynolds**: Include customer name on trade-in custom field
- Scrape amazon best sellers per department and enrich via getByAsin
- **paso-rapido**: A RNC implies fiscal credit invoicing
- Amazon best sellers scraper + memory-safe homepage tag rotation
- **movipass**: Sync vehicle tag data on balance check
- **scribe**: Add Typesense/Algolia @search to invoice, quote, sales receipt, bill, expense
- Fall back to last lead on responder when no active lead
- Facet only is_filtrable attributes in typesense product index
- **reynolds**: Store submitted trade-in form on lead for SalesAssist import
- **payments**: Allow use_hold override on processPayment
- Slack agent connection
- Add people relationship
- Update agent tools
- Add acumatica agent
- **souk**: Exclude configurable states from order turnover
- **reynolds**: Store trade-in in VinSolution shape + assign owner on RCI disposition
- Add agentRuntimeRetryDeployment mutation for failed deployments
- Poll-friendly live container status query + broadcast on drift
- Add demo account
- Unify order-item corrections into a single atomic adjust-amount batch
- Split order-item amend correction into add/remove/update actions
- Add includeSummary flag to exportOrderPayments
- **workflow**: Index ReceiverWebhookCall in Scout / Algolia
- Add ExportMetadataInput and configurable header color to exportOrderPayments
- Update wasup key
- Add wasup integration
- Expose causer user on ActivityLog type
- Add agent receptionist type
- **reynolds**: Auto-create LeadType/LeadStatus/LeadSource from inbound
- **reynolds**: Add PushLeadNotesActivity mirroring VinSolution's shape
- Allow agent config backups to use a dedicated S3 bucket
- **movipass**: R relocate — RelocateVehicleAction swaps location variant in-place + FE guide
- **movipass**: T6 associate-payment — transfer payment between orders with status rollback
- Rename correctOrder → amendOrder, fix stale response, expose paginated activityLogs on Order

### Fixed

- Keep KanvasConversationStore as-is per team decision
- **intelligence**: Match KanvasConversationStore to the current laravel/ai ConversationStore contract
- **intelligence**: Dedupe agent tools by name and add customer filter to list_overdue_invoices
- **acumatica**: Push AP payments via a two-step Check PUT and use the real Acumatica reference
- **acumatica**: Route AP payment applications through the Check entity, not Payment
- **scrapingdog**: Use effective price in cost calculation
- Report lead messaging failures without throwing
- Lead channel msg
- Apply limits to corporative
- Make LoCompro tax calculation deterministic
- **guild**: Resolve Twilio sender for lead SMS
- Resolve email follow-up subject from lead or people active lead
- First msg stop
- Slack agent connection
- **mindee**: Update Client to Mindee SDK v3.1.0 namespaces
- Use app key (not non-existent app uuid) in config-backup S3 path
- **intelligence**: Drop the ACUMATICA_ENVIRONMENT staging-only gate
- **acumatica**: Revert nginx proxy timeout change, trim comment
- Scrapingdog best sellers robustness + bundle writes + variant observer null-safety
- Restore soft-deleted product when re-importing amazon best sellers
- Tag homepage rotation via limited pluck+get instead of cursor
- Move bestsellers url default out of signature to satisfy larastan
- **paso-rapido**: Use the corporate provider company RNC on the invoice
- **payway**: Strip plus-addressing from clienteCorreo and support save_card
- **movipass**: Relink variant warehouses when a user upgrades to corporate
- **social**: Cast Message searchable created_at/updated_at to int64
- **social**: Guard Message::getMessage against non-array JSON decode (#10440)
- Typesens id (#10436)
- Typesens id (#10433)
- Internal search (#10424)
- **stripe**: Omit empty after_completion redirect URL on payment links (#10425)
- **stripe**: Omit empty after_completion redirect URL on payment links (#10427)
- Address review feedback (redundant app relation, 8.4 paren style)
- Search by phone
- Credit app test
- **payway**: Use markAsPaid() to propagate paid status to the order
- **regions**: Expose default_region_id in me.custom_fields
- Pull latest base image when building the shared agent image
- **currency**: Trim trailing space from DOP currency code
- **cardnet**: Correct commit/refund/void bodies and gateway request quirks
- **workflow**: Resolve integration company by tenant, not by region
- Assign Bouncer role when registering an existing user into a new app
- Assign configured global company when registering an existing user new to the app
- Allow global regions in integration company setup
- Use agent firstname in lead ai mode note
- Cap movipass late fee at a single one-time charge
- Transition receiving order to paid on associate-payment correction
- Rename colliding private query() helper in EventAnalyticsServiceTest
- Resolve global default region in variant metadata via flag-gated trait
- **reynolds**: Sequential People lookup — email first, phone fallback
- Match order-correction productType by slug instead of name
- Lead intent tool
- Match order-correction productType by name instead of slug
- AmendOrder no longer requires view-module-commerce module access
- Resolve global default region in variant metadata via trait
- **leads**: Pass status array to whereIn without spread
- **salesassist**: Fall back to email lookup in Reynolds PullLead branch
- Empty default value
- Resolve global default regions (companies_id=0) in variant metadata
- **reynolds**: Preserve existing Lead fields on partial LDU updates
- **region**: Restore settings as String for backward compat, add settingsData
- **reynolds**: Map real envelope shape + restore ActionEngine branches
- **movipass**: Prevent duplicate late-fee items under concurrent runs
- Correct item selection in adjust-amount and relocate corrections
- Address review feedback on agent config backup PR
- **reynolds**: Anchor inbound People sync to prevent duplicates
- AgentConfigBackups schema conflict breaking GraphQL schema boot
- Prevent chat history and workspace data loss on OpenClaw→Hermes migration
- Agent runtime deploy, channel-token restart, and openclaw backup
- **movipass**: T6 rollback — handle post-paid statuses and fulfillment reset

### Changed

- **intelligence**: Move Acumatica write tools into their own Tools/Acumatica namespace
- Add tool context
- Add agent reporting commission
- Add category to tools
- No nee dfor interface
- Revert accidental docker-compose.yml change from local branch cleanup
- Upgrade to gemini 3.5 flash
- Laravel conversion storage
- **paso-rapido**: Translate inline comments to english
- Rotate homepage tag
- Twilio noice cleanup
- Move action to general data
- Comment apply lead ai mode
- Template manly honda
- Move to company level
- Clean up code
- Activity for follow up manly
- Fix unkown country
- **salesassist**: Single-query Reynolds lead lookup with contact + custom-field OR
- Customer facing agents
- Hotfix ssh agent connection
- **reynolds**: Render note text via MessageNotificationTextService
- **reynolds**: PushLeadNotesActivity v1 — just push message content
- **reynolds**: Use Contact::cleanPhone for inbound phone normalization
- **reynolds**: Use LeadModel::getByCustomField for prospect lookup
- **salesassist**: Drop redundant PROSPECT_ID short-circuit in Reynolds branch

### Dependencies

- Bumped 25 package(s) via Dependabot, including `actions/setup-python`, `algolia/algoliasearch-client-php`, `doctrine/dbal`, `durable-workflow/workflow`, `google/cloud-discoveryengine`, `guzzlehttp/guzzle`, `laravel/ai`, `laravel/boost` and others.

## June 2026

### Added

- **reynolds**: PushLeadAction upserts — delegates to USL when prospect exists
- Manly honda stage-config follow up engagement
- DuplicateOrders query — scopeDuplicate, isDuplicate/getDuplicateOfOrderNumber accessors, Order type fields
- T5 duplicate detection — MarkOrderAsDuplicateAction, ScanDuplicateOrdersCommand using order type config, tests
- T4 adjust-amount correction — finds service item by product type, recalculates total, tests
- T2 add-observations correction — action, resolver dispatch, tests
- **movipass**: T1 — correct vehicle plate correction action
- **movipass**: Impound lot setup command + correction base infrastructure
- Trigger fresh workspace snapshot before config backup when container is running
- Apollo email validation
- Include full container workspace in agent config backup ZIP
- **reynolds**: Inbound webhook receiver for SalesAssist push events
- Expand agent config backup to include files, skills, and agent-owned tools
- Agent config backup and restore to S3
- External AI channel response activity
- **salesassist**: Route PullPeople through Reynolds branch

### Fixed

- Explode on create lead first engagement
- Completion status tool
- First search company and after resolve by app
- Issue with apollo
- Respect company timezone for daily agent config backup
- **intelligence**: Attribute local rollup snapshot to the agent's company

### Changed

- Set client id if not exist
- Change to array
- Move correction base to Souk + generic correctOrder mutation
- Search first by custom field
- Lead to array
- Pull lead act
- Email validation receiver
- **reynolds**: Resolve webhook tenant by precomputed composite key
- Remove auto config backup on agent save
- Fix people cold down
- **reynolds**: Route inbound webhook through the existing ReceiverController
- Zip all backup contents (manifest + files) into a single S3 archive
- Require both from_ia and from_orchestrator to gate external AI send
- Agent address parser
- User msg flag
- **reynolds**: Restore PushLeadActivity as thin subclass of SalesAssist meta

### Dependencies

- Bumped 4 package(s) via Dependabot, including `actions/cache`, `brick/math`, `guzzlehttp/guzzle`, `laravel/framework`.
