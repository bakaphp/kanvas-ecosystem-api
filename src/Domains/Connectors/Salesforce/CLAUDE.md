# Salesforce — Kanvas Ecosystem API

Loads when work touches `src/Domains/Connectors/Salesforce/`.

## The property sync is GAGroup's data model, hardcoded

The connector has two halves, and only one of them is generic:

| Half | Salesforce objects | Tenant-specific? |
|---|---|---|
| Leads, people, organizations, deals | `Lead`, `Contact`, `Account`, `Opportunity` (standard) | No. Field maps are per company (`CustomFieldEnum::*_FIELDS_MAP`). |
| Properties | `Project__c`, `Location__c`, `Location_Contact__c`, `Project_Contact__c` (custom) | **Yes.** Object and field names are literals in the code. |

The custom objects and their fields (`Parent_Deal__c`, `Deal_Status__c`, `Marketing_Status__c`,
`Store__c`, ...) exist only in GAGroup's org. They live in `PullPropertyAction::LOCATION_FIELDS` and
`attributesFromPayload()`, in `PullProjectAction`, in the two contact actions and in
`ImportSalesforcePropertiesCommand`.

**If a second tenant needs a property sync, do not add a second set of literals beside these.** Move
the object name, the parent lookup field and the field → attribute map into per-company
configuration, the way the `*_FIELDS_MAP` custom fields already do it for leads and contacts.

The `Location__c` webhook is the strictest point. `PullPropertyAction` re-reads the row with the
full `LOCATION_FIELDS` list plus `Parent_Deal__r.Name`, so an org that has a `Location__c` object
without those fields gets a SOQL error on every Outbound Message, not a partial import.

## A location is a variant of its project's product, never a product

`Project__c` → Product `sf-project-<Id>`; `Location__c` → Variant `sf-location-<Id>` under it. The
import and the webhook both go through `PullProjectAction` with rows built by
`PullPropertyAction::variantFromPayload()`.

A SKU is looked up company-wide when variants are upserted, so a location's variant living under any
other product captures every later import of that location and it never reaches its project. Do not
create a standalone product for a location, in a webhook or anywhere else.

The location's Salesforce id is read back from the variant SKU
(`PullPropertyAction::salesforceIdFromSku()`), not from a custom field.
