# WordPress Connector

Two unrelated integrations share this folder — don't confuse them:

| Class | Talks to | Auth |
|---|---|---|
| `Client` / `AjaxClient` / `AlgoliaClient` / `WidgetClient` | a dealer site's public inventory JSON endpoint (scraping) | none |
| `RestClient` | the WordPress core REST API (`/wp-json/wp/v2`) | Application Password (Basic) |

Everything below is about `RestClient` and publishing.

## Publishing a message as a post

`PushMessageToWordPressAction` turns a Kanvas `Message` into a wp/v2 post, driven by
`PushMessageToWordPressActivity` on the message-created rule: the agent writes a post-shaped
message and the rule ships it.

**There is deliberately no WordPress GraphQL surface.** Publishing is a workflow reaction to a
message, not something a client calls — the message-creation mutations already exist and are the
only entry point needed. Don't add a `wordpressPublishMessage`-style mutation.

Re-running is an **update**, not a second post: the wp post id is stored on the message
(`WORDPRESS_POST_ID` + `WORDPRESS_POST_SITE_URL`) and reused as long as the site hasn't changed.

## Message body structure

Post fields live at the top level of the message body, or nested under a `wordpress` key when the
message is also doing chat duty. Every field is optional.

```json
{
  "title": "Kanvas ships WordPress publishing",
  "content": "<p>Body. HTML is passed through untouched.</p>",
  "excerpt": "Shown in listings and feeds",
  "slug": "kanvas-ships-wordpress-publishing",
  "status": "publish",
  "date": "2026-08-20T10:00:00Z",
  "author_id": 5,
  "categories": ["News", "Product"],
  "tags": ["ai", "kanvas"],
  "featured_image": "https://cdn.example.com/hero.jpg",
  "attachments": ["https://cdn.example.com/deck.pdf"],
  "comment_status": "open",
  "ping_status": "closed",
  "sticky": false,
  "format": "standard",
  "password": null,
  "meta": { "any_registered_meta_key": "value" }
}
```

- `status` — `draft` (default) / `pending` / `private` / `publish` / `future`. Pair `future` with
  `date` to schedule.
- `categories` / `tags` — names or numeric term ids, mixed freely. A name that doesn't exist on the
  site is created, unless `wordpress_allow_term_creation` is off, in which case it's skipped.
- `featured_image` / `attachments` — URLs. They're fetched through `SafeUrlFetcher` (SSRF-guarded)
  and uploaded to the media library. A URL that can't be fetched is reported in `media_failures`
  and does not sink the post.
- `video` — a URL, uploaded like the rest but also **embedded as a `wp:video` block at the top of the
  content**, so the post leads with the player. Uploading alone is not enough: an attachment with no
  block is filed in the media library and never rendered. The poster frame stays `featured_media` —
  that is what archives and social cards read, and no theme can render an mp4 as a thumbnail. The
  block is emitted only when the upload returns both an id and a `source_url`; otherwise the post
  ships as it would have without the clip rather than with a `<video src="">`.
  Post **format** is left alone — wp/v2 rejects `format: "video"` on a theme that does not declare
  post-format support, so set it explicitly in the message or rule when the theme has it.
- `meta` — only keys the site has registered with `show_in_rest` will stick.

### Fallbacks when the body omits a field

| Field | Falls back to |
|---|---|
| `title` | first line of the content, trimmed to 120 chars |
| `content` | the message's `text` / `message` / `body` key, or a plain-string body |
| `slug` | the message's own slug |
| `categories` | the message's Kanvas categories (`HasCategoriesTrait`) |
| `tags` | the message's Kanvas tags (`HasTagsTrait`) |
| `featured_image` | first image in `$message->files` |
| `video` | first video in `$message->files` |
| `attachments` | the remaining `$message->files` |
| `status` / `author_id` / default terms | the connector configuration |

**Do not fall back to `Message::contentText()` for an object body.** Its last-resort branch returns
the raw JSON when it finds no text key, which would publish the message's own JSON as the post —
`WordPressPost::resolveContent()` reads the object by key instead and only uses `contentText()` for
a genuinely non-object body.

### An agent reply that IS the post (`response_json`)

A message written by a channel responder does not carry the post at the top level. The agent's reply
travels as **text**: `ChatHelper::extractTextFromResponse()` picks ONE field out of the agent's JSON
(so the email/WhatsApp body is prose, not a JSON dump), and everything else — title, terms, excerpt,
status — is thrown away. An agent that wrote a whole post would arrive with only its body, and the
title would be the first 117 characters of the article.

So `BaseAgentChannelReplyAction::createMessage()` keeps the decoded envelope on the message as
`response_json` alongside the reply text, and `WordPressPost::fromMessage()` reads it as a layer:

```json
{
  "content": "<p>The reply text that was actually sent.</p>",
  "from_ia": true,
  "response_json": { "title": "…", "content": "<p>…</p>", "categories": ["News"], "status": "draft" }
}
```

`response_json` is deliberately connector-agnostic — the responder does not know WordPress exists, it
only records that the agent answered with structure. Any activity can consume it.

Messages written before that existed (or by a producer that stores the reply verbatim) are still
handled: a **string** `response_json` / `response_text` / `responseText` / `content` is run through
`ChatHelper::extractJsonEnvelope()`, which unwraps a ```` ```json ```` fence. Without that, a fenced
reply publishes the raw JSON as the article body — a silent wrong post rather than a loud failure,
since `content` is itself a valid post key.

**An envelope can be a LIST.** An agent handed several press releases in one turn answers with
`[{...},{...}]`. A post is one record, so `agentEnvelope()` reads the first — the rest stay on the
message in `response_json` for whatever consumes them next. Getting this wrong is silent: a list
reaches `onlyPostKeys()` as numeric keys, matches nothing, and the post falls through to the
message's own `content`, publishing the model's raw JSON as the article under a title that is its
first 117 characters. The parse side matters as much — `extractJsonEnvelope()` used to anchor its
fenced and bare matches on `{`, so a fenced list was never decoded at all.

Only the first record is published, so an agent that regularly has several stories to file should be
instructed to answer with **one article per turn** — the extra records survive on the message but
nothing ships them.

### Precedence

`workflow rule status` > `message body wordpress: {}` > `response_json` > `message body top level` >
`workflow rule params` > connector config.

**`status` from the workflow rule is the one field that outranks the message.** It is editorial
policy, not content: a rule configured to hold everything for review must not be overruled by an
agent that wrote `"status": "publish"` into its envelope. Everything else the rule sets stays a
default the message can override — categories and tags describe the article, and whoever wrote it
knows those better than the rule does.

The promotion is scoped to the **rule's** status (`PushMessageToWordPressAction::statusOverride()`),
not the connector's `default_post_status`. That one stays a site-wide default, so a message naming
its own status still wins over it.

Only the keys wp/v2 understands are read out of each layer, so agent bookkeeping in the message
body — and the editorial extras a news agent emits (`titulos_alternativos`, `correcciones`) — can
never reach the site.

## Setup

Setup goes through the **generic** `integrationCompany` mutation, not a bespoke one — that path
also creates the `integrations_companies` row `executeIntegration` needs. A `wordpressSetup`
mutation would configure the credentials and then the activity would bail with "No integration
configured for this company".

```graphql
mutation {
  integrationCompany(input: {
    integration: { id: "<wordpress integrations.id>" }
    region: { id: "<region id>" }
    company_id: "<company id>"
    config: {
      site_url: "https://blog.example.com"
      username: "kanvas-bot"
      application_password: "abcd efgh ijkl mnop qrst uvwx"
      default_post_status: "draft"
    }
  }) { id }
}
```

Credentials are stored at **company** level (a site belongs to a tenant); `RestClient` falls back to
app level per key, so an app-wide default site with per-company overrides works.

The application password comes from WP Admin → Users → Profile → Application Passwords. WP prints it
in spaced groups; the spaces are display-only and `RestClient` strips them. The user needs at least
the Author role — `WordPressHandler::setup()` rejects anything that can't `publish_posts`.

### Why a valid-looking setup still 401s

Application Passwords are Basic auth, and Basic auth is fragile in two specific ways:

1. **Redirects drop the credential.** Guzzle strips `Authorization` across any host *or* scheme
   change, so `http://site.com` (→ https) or `https://site.com` (→ www) arrives unauthenticated.
   `RestClient` refuses to follow redirects and raises an error naming the `Location` target —
   set `site_url` to the exact Site Address from WP Settings → General.
2. **Some hosts strip the header.** Apache with CGI/FastCGI does not pass `Authorization` to PHP,
   and WP has no fallback. Symptom is `rest_not_logged_in` on a password that works in the browser.
   Fix is on the WP side: `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` in `.htaccess`.

Also site-side, not fixable from here: WP disables Application Passwords entirely over plain HTTP;
security plugins (Wordfence, iThemes) can block the REST API; and with permalinks set to "Plain"
the `/wp-json/` route 404s and only `?rest_route=` works — `RestClient` does not implement that
fallback.

Seed row (`apps_id = 0` = available to every app):

```sql
INSERT INTO integrations (name, uuid, apps_id, config, handler, actions_id, receivers_id, is_deleted, created_at, updated_at)
VALUES (
  'wordpress',
  UUID(),
  0,
  '{"site_url": {"type": "text", "required": true}, "username": {"type": "text", "required": true}, "application_password": {"type": "text", "required": true}, "default_post_status": {"type": "text", "required": false}, "default_author_id": {"type": "text", "required": false}, "default_categories": {"type": "text", "required": false}, "default_tags": {"type": "text", "required": false}, "allow_term_creation": {"type": "text", "required": false}}',
  'Kanvas\\Connectors\\WordPress\\Handlers\\WordPressHandler',
  NULL, NULL, 0, NOW(), NOW()
);
```

## Workflow wiring

Attach `PushMessageToWordPressActivity` to the message-created rule. Rule params:

- `message_type_id` — **set this.** Without it every chat message in the app is a publish candidate.
- anything from the body structure above, used as defaults the message can override.

The activity flags a FAILED workflow (visible in integration history, no Sentry noise) instead of
throwing for the expected skips: missing credentials, wrong message type, locked message, empty
content. Kanvas `is_public` is deliberately **not** consulted — the post's own wp `status` decides
site visibility, so a private Kanvas message can still ship as a WP draft.
