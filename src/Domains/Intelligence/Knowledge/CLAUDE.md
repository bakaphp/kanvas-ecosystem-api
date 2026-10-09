# Knowledge (RAG) — Kanvas Ecosystem API

Loads when work touches `src/Domains/Intelligence/Knowledge/`. Read it before adding a knowledge source,
changing retrieval, or advising a tenant on what to upload.

## What actually happens

**Indexing.** An uploaded document is split by `KnowledgeChunker`: on blank lines, packing paragraphs
together up to 1,000 characters. Each chunk is embedded and stored in Typesense with its scope (agent,
record, or organization). A lead's own messages are indexed as record history by `LeadKnowledgeSource`.

**Pre-turn recall** (`HasKnowledgeRag`, every reply of a RAG agent):

1. Customer-facing agents may rewrite the inbound message into a query first (`GatedQueryRewrite`). It is a
   model call the customer waits on, so it runs only for a terse message (≤ 6 words) or a long one (≥ 40),
   on the agent's model with Gemini thinking `low`. A plain sentence searches as written. Each rewrite logs
   `Agent query rewrite` with `duration_ms`; a failed rewrite searches with the original text.
2. Search the agent's documents and the record's rows, top `neuron_lead_rag_result_limit` (default 8).
3. `KnowledgeRetrieval::rank()` gives the agent's documents first claim on the slots, record rows fill the
   rest, a hit identical to the question is dropped.
4. `AdaptiveThresholdPostProcessor(0.6)` drops hits below `median − 0.6 × MAD`. **This is relative to the
   batch, not a quality bar**: a batch that is all noise at ~0.6 passes almost whole.

**`search_knowledge` tool** runs the same search without step 1 and step 4, plus company memory, and returns
at most 5 results (`MAX_RESULTS`), keeping up to 2 for memory hits when there are any.

Whatever comes back stays in the conversation for the rest of the turn and is re-sent on every step, so
eight useless chunks are paid for several times over.

## What RAG is good for, and what it is not

The search measures **similarity of meaning** between the question and a chunk. It does not understand
"this rule applies here". A chunk is found only when it sounds like the question it should answer.

| Material | RAG? | Why |
|---|---|---|
| Facts looked up occasionally: addresses, hours, lot lists, price sheets, financing terms, product specs | ✅ | The customer's words and the fact share meaning ("where is the lot" ↔ "Lot address: …") |
| Situational playbooks, one per situation, opening with the trigger in the customer's words | ✅ | "When the customer asks for photos or details…" sits next to "send me pictures" |
| Large corpora that do not fit the prompt: manuals, catalogues, policies | ✅ | The reason RAG exists |
| The record's own past (lead history) | ✅ | Already indexed per lead |
| Always-on behaviour rules ("never say a vehicle is unavailable", "never mention tools") | ❌ prompt | A rule that applies only when a search happens to return it is not a rule |
| A rulebook small enough for the prompt (≲ 5–8K tokens) | ❌ prompt | Whole and in order beats fragments, and the prompt is cached |
| Text written about the assistant ("The AI Assistant must…", "Execute handoff_lead") | ❌ | Shares no meaning with customer questions; scores flat against everything |
| How to use the agent's own tools | ❌ | The tool descriptions are already in the prompt |
| Automated trigger prompts | ❌ | Recalled later, they read as a fresh request. Run those turns with `privateUserTurn: true` |

Measured case (2026-10-07, agent 1318): 11 chunks of handoff and inventory rules. "financing down payment"
and "test drive appointment" both returned all 11 at 0.571–0.627; the one relevant chunk was inside the noise.
No score floor can separate that. It is a content problem.

## Writing a document that retrieves well

- **One topic per section, a blank line between sections.** The chunker splits on blank lines; a PDF export
  with hard line breaks and no blank lines packs unrelated rules into one 1,000-character chunk.
- **Open each section with the situation in the customer's words**, then the answer or the instruction.
- **Make each section stand alone.** A chunk that starts "2. The customer will receive follow-up" means
  nothing once it is cut from item 1.
- **Name things concretely.** "Shepard CDJR lot, 123 Main St" retrieves; "the second location" does not.
- **Keep instructions to the agent in the prompt**, and keep knowledge for what the agent looks up.

## Diagnosing noisy recall

Dump the scores before touching any setting:

```php
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Illuminate\Support\Str;

$agent = \Kanvas\Intelligence\Agents\Models\Agent::find($id);
$store = KnowledgeComponents::store($agent->app);
$emb = KnowledgeComponents::embedder($agent->app)->embed('a real customer question');
collect($store->search($emb, KnowledgeScope::forModel($agent), 15))   // forModel, not fromEntity
    ->map(fn ($h) => [round($h['score'], 3), Str::limit(Str::squish($h['content']), 80)])->all();
```

Run it for a question the documents answer and one they do not.

- **Relevant hits clearly above the rest** (e.g. 0.75 vs 0.60): set `neuron_lead_rag_min_score` between them.
  That is the only case a floor helps.
- **Everything within a few hundredths**: the content does not retrieve. Move rules to the prompt, rewrite
  playbooks as trigger-titled sections, keep facts. Do not tune settings.
- **Every query returns the whole set**: the agent has very little knowledge. It is probably small enough
  for the prompt.

## Settings

| Setting | Default | Notes |
|---|---|---|
| `neuron_lead_rag_enabled` | off | Turns the knowledge half on for the app |
| `neuron_lead_rag_result_limit` | 8 (max 20) | Chunks per search; each costs tokens on every later step |
| `neuron_lead_rag_min_score` | unset (no floor) | Absolute similarity floor. Set only from measured scores |
