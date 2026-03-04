# MEMORY.md - Tetsuo's Long-Term Storage

## 🧠 Knowledge Graph

### 🏗 Architecture: Kanvas Ecosystem
- **Style:** Modular Monolith with DDD.
- **Core:** `src/Kanvas` (Identity, ACL, Apps).
- **Domains:** Isolated in `src/Domains` (Inventory, Guild, Social, Souk, Intelligence, Connectors).
- **Database:** Multi-database (MySQL), Polyglot Persistence (Redis, Meilisearch, Typesense).
- **Tech Stack:** PHP 8.4+, Laravel 12, GraphQL (Lighthouse), Python/Go (secondary).
- **Patterns:** Heavy Trait usage, Strict DTOs (`spatie/laravel-data`), Hexagonal/Clean Architecture within Domains.

### 🔌 Project: PromptMine (Connector)
- **Path:** `src/Domains/Connectors/PromptMine`
- **Purpose:** GenAI Content Factory (Images, Videos, "Nuggets").
- **Key Workflows:**
    - `PromptImageFilterActivity`: Handles image generation.
        - Providers: `fal.ai` (Polling), `OpenAI` (DALL-E), `Gemini-Nano-Banana`.
        - Logic: Deducts credits -> Generates -> Optimizes -> Uploads -> Notifies.
        - Risks: Blocking `while` loops for polling, credit refund race conditions.
    - `ProcessVideoRequestAction`: Video generation.
    - `CheckNuggetGenerationCountActivity`: Enforces quotas/IAP limits.
- **Key Services:**
    - `ImageOptimizerService`: Optimizes generated content.
    - `FilesystemServices`: Handles storage.
    - `DistributeMessagesToUsersAction`: Pushes content to feeds.
- **Observations:**
    - Uses "Magic Strings" for model names (`fal-ai/`, `cartoonify`).
    - Error handling relies on `report($e)` inside loops.

## 📝 Current Focus
- **User:** Max (Backend Lead).
- **Active Task:** **MK-3964** - Expose Usage Data in API.
    - **Goal:** Update `UserPrompt` schema with `usage_count` and `last_used_at`.
    - **Status:** To Do.
- **Backlog:**
    - MK-3959: Fix orphaned outputs in search.
    - MK-3958: Investigate image upload failures.

## 🎟 Jira Context
- **Board:** MK Board 62.
- **My User:** `tetsuo@promptmine.ai`
