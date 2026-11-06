# Lead memory evaluation datasets

`lead-summary.json` is a list of transcripts. Each item:

- `name`, `category` (`corrections`, `cancelled_appointments`, `old_promises`, `document_lookup`), `title`
- `messages`: `{id, date, speaker, text}` oldest first; ids are what the model cites, any unique ints
- `expect_open`: lowercase keywords that must appear in the open items at the end; empty when nothing should stay open

The four committed items are synthetic. Before the rollout goes past the first tenant (D5/D11), add
anonymised transcripts from real leads in the same shape: names, phones, emails and addresses replaced,
dates and the order of messages kept.
