# People Classification

Loads when work touches `src/Domains/Guild/Customers/Classification/`. One structured LLM call turns a
job title into a seniority level and a functional area from a per-tenant taxonomy. Generic: nothing
here knows about a connector. Off unless a company or app turns it on.

## Settings (company, then app)

| Setting | Effect |
|---|---|
| `people_classification_enabled` | automatic classification on create, update and connector pulls; unset is off |
| `people_classification_taxonomy` | `{"levels": {CODE: {label, keywords, tag}}, "areas": {...}}`; without it nothing runs |
| `people_classification_min_confidence` | default 0.85; below it a result waits for review |
| `people_classification_apply_tags` | also write the taxonomy's tags; unset is off |
| `people_classification_llm_config_id` / `people_classification_model` | model override; default Flash-Lite |

## Fields on People

`nivel_jerarquico`, `area_funcional` hold applied values only. `classification_source` is `ai`, `manual`
or `pending_review`. A low-confidence result goes to `classification_suggested` and leaves the applied
values empty. `normalized_title`, `classification_confidence` and `classification_hash` are detail.

## Rules

- **A manual choice is never reclassified**, not even by `--force`.
- **Never `syncTags()`.** `PeopleClassificationTagsService` touches only the taxonomy's tags. The reverse
  happens too: `UpdatePeopleAction` syncs tags to what the form sent, so `dispatchIfEnabled()` reconciles
  them even when the title is unchanged.
- **A tag must be set explicitly when a label has a comma.** `Tag::normalizeNames` splits a string on
  commas, so a label like "Mercadeo, Ventas & Comercial" needs its own `tag`.
- **Results are cached per app and title for 30 days**, keyed with the taxonomy hash. A taxonomy edit
  reclassifies on the next run. A title's cached answer is shared by every company of the app that has the
  same taxonomy, whichever LLM config produced it. `--force` skips the cache and asks the model again.
- **`people-classified` fires only when applied values change**, and never for `pending_review`.
- **Every change of the applied values leaves a record:** a People note tagged `classification` and a
  `guild.people.classified` ledger event, both with the old and new values. That includes values cleared by a
  low-confidence result. Re-running with the same outcome writes neither. A backfill writes one of each per
  person it changes.

## Running it

```bash
php artisan kanvas:classify-people {app} {company} --limit=200 --sync   # prints each result, read before trusting
php artisan kanvas:classify-people {app} {company}                      # queues one job per person on the default queue
```

The command runs regardless of the enabled flag and needs only a taxonomy.

## Turning it on for another app

Nothing runs until an app or company has both a taxonomy and the enabled flag. There is no platform default
taxonomy: categories are a tenant's business decision, and INTRAS's list lives in its connector.

1. Agree the categories with the client and set `people_classification_taxonomy`:
   ```json
   {
     "levels": {"GERENTE": {"label": "Gerencia", "tag": "Nivel: Gerencia", "keywords": ["Gerente", "Manager"]}},
     "areas": {"RRHH": {"label": "Recursos Humanos", "tag": "Área: RRHH", "keywords": ["HR", "Talento"]}}
   }
   ```
   Keys are the stored values and the only answers the model may give. Both groups are required, or the
   setting is treated as missing. `tag` falls back to `label`.
2. Set `people_classification_apply_tags` if the client wants tags.
3. Sample with `kanvas:classify-people {app} {company} --limit=200 --sync` and read the results. This writes
   real values, notes and ledger events. Then run it without `--sync` to backfill.
4. Set `people_classification_enabled` to classify new people and title changes.

A connector with its own system adds a push the way INTRAS does below; the classifier needs no change.

## INTRAS / SIPGO

The connector side is `Connectors/Intras`: the taxonomy (`Support/IntrasPeopleTaxonomy`), the pull hook and
the push. `nivel` and `area` are SIPGO's own lookups and stay untouched; the classification lives in two new
SIPGO custom fields, and the pull never reads them back.

1. `kanvas:intras-setup-people-classification {app} --dry-run`, then without it (writes to SIPGO)
2. Turn on `people_classification_apply_tags` first: the backfill writes tags only when it is on
3. Sample with `kanvas:classify-people`, then run it for each of the 4 companies
4. `kanvas:intras-push-people-classification {app} {company} --dry-run`, then without it
5. Per company, a rule of type `people-classified` on People running `IntrasPushPeopleClassificationActivity`
6. Turn on `people_classification_enabled` last, so nothing classified from here on misses the rule
