# Campaigns — batch email to leads and people

Loads when work touches `src/Domains/Guild/Campaigns/`. One `lead_campaigns` row per batch, one
`lead_campaign_recipients` row per slot, `ProcessLeadCampaignJob` fans out and records the result per
slot. `tests/Guild/Campaigns/` covers the job and the actions.

## Three tool fronts, one action

| Tool | Takes | When |
|---|---|---|
| `create_lead_campaign` | people ids, one message, shared attachments | the same email to a list |
| `create_people_campaign` | rows of `{person_id, variables, attachment_urls}`, plus a file matcher and `test_to` | each person gets something of their own |
| `add_people_to_campaign` | people ids | append to a campaign still scheduled for the future |

All three run `CreateBatchCampaignAction` / `AddRecipientsToCampaignAction`, which accept per-row
`attachment_urls` and `variables` whether or not the front exposes them.

## What a recipient row can carry

- `attachment_urls` — this person's own files. The job sends the campaign's shared list first, then
  these, duplicates removed. A null column sends exactly what the campaign has.
- `variables` — free-form template context. `SendEmailToPeopleAction` builds the context in this order,
  later wins: `content` (the message), `people`, then `variables`, then the system keys (`company`,
  `noHi`, `signature`). So an agent may override `content` or `people` for one person and can never
  replace the company.

`People` is on the `ExposesTemplateData` allow-list (`toTemplateData()`): a template reads name,
firstname, lastname, dob, email, phone and `custom_fields`, and nothing else from the row. The `Blank`
notification also hands the notifiable People in as `user`, so that key is allow-listed the same way.

## File-to-person matching happens in the tool, not the job

`CampaignAttachmentMatcherService::match()` pairs uploaded `Filesystem` rows with people: the file name
without its extension, trimmed and case-folded, equals the person's custom field named by
`attachment_match_field`. Several files may land on one person. A person with no file is **excluded**
with `compliance_status: missing_attachment`, the same channel as do-not-contact, so the agent reports
them; files that match nobody come back as `unmatched_files`. There is no recipient status for it and
the job never sees an unmatched person. Files are loaded `fromApp` + `fromCompany`, so an id from another
tenant matches nothing.

## `test_to` is a closed set

A test send goes only to a user of the current company (`ResolvesCompanyUserForTool`, exact email
match). It renders the first eligible recipient, sends through `SendEmailToPeopleAction` with
`trackOpens: false` so the teammate's open never lands on the person, creates no campaign and no
recipient rows, and writes no history on the person. Any other address is `denied`.

## Don't

- Don't put the matcher in the job: a recipient without its file must be reported before the send,
  not skipped during it.
- Don't let `variables` reach the template unflattened: the sandbox gets arrays only, and
  `TemplateSandbox` refuses methods, so a model in `variables` would be a leak, not a feature.
- Don't render the subject per recipient here; it is a plain string on the campaign.
