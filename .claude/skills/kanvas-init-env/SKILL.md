---
name: kanvas-init-env
description: >
  Initializes a fresh Kanvas local environment: creates a new App, registers an
  admin user (which auto-creates the Company and Company Branch), and returns
  the API key + auth token needed to start testing. Invoke whenever the user
  says "init env", "setup local", "create new app", "bootstrap kanvas",
  "new local environment", "spin up kanvas", or wants to start from scratch
  on a fresh Kanvas install.
---

# kanvas-init-env

Bootstraps a new Kanvas local environment inside the `phpkanvas-ecosystem`
Docker container. Creates: App → registers Admin User (auto-creates Company +
Branch) → returns credentials.

**Prerequisites:** `kanvas:setup-ecosystem` must have already been run (all
migrations applied, base seeders done). If not, run the `sync-kanvas-db`
skill first.

---

## Step 1 — Verify the ecosystem is seeded

Check that the default Kanvas app (id=1) and an owner user (id=1) exist,
since `kanvas:app-create` requires an existing user as owner:

```bash
docker exec phpkanvas-ecosystem php artisan tinker --execute="
echo 'Users: ' . \Kanvas\Users\Models\Users::count() . PHP_EOL;
echo 'Apps: ' . \Kanvas\Apps\Models\Apps::count() . PHP_EOL;
"
```

If counts are 0, the base seeders haven't run. Run:

```bash
docker exec phpkanvas-ecosystem php artisan db:seed
```

---

## Step 2 — Create the App

Ask the user for (or use these defaults if they say "use defaults"):
- **name** e.g. `"Local Test App"`
- **domain** e.g. `"localhost"`
- **url** e.g. `"http://localhost"`
- **owner email** — the existing user who will own this app (default: the
  seeded user, usually `admin@kantvasapp.com` or `anonymous@kanvas.dev`)
- **ecosystem_auth** — `true` (share auth with Kanvas ecosystem)
- **payments_active** — `false` for local testing

Create via tinker (non-interactive, scriptable):

```bash
docker exec phpkanvas-ecosystem php artisan tinker --execute="
use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Users\Models\Users;

\$owner = Users::first();
\$data = AppInput::from([
    'name'             => 'Local Test App',
    'url'              => 'http://localhost',
    'description'      => 'Local development app',
    'domain'           => 'localhost',
    'is_actived'       => 1,
    'ecosystem_auth'   => 1,
    'payments_active'  => 0,
    'is_public'        => 1,
    'domain_based'     => 0,
]);
\$app = (new CreateAppsAction(\$data, \$owner))->execute();
echo 'App created!' . PHP_EOL;
echo 'ID:  ' . \$app->id . PHP_EOL;
echo 'Key: ' . \$app->key . PHP_EOL;
" 2>/dev/null
```

Save the **API Key** from the output — every subsequent request needs it as
the `X-Kanvas-Key` header.

Alternatively, run the interactive artisan command:

```bash
docker exec -it phpkanvas-ecosystem php artisan kanvas:app-create
```

---

## Step 3 — Register the Admin User (auto-creates Company + Branch)

The `register` mutation creates the user, a default Company, and a default
Company Branch in one call. The first user registered in an app gets the
`Admin` role by default.

Replace `<APP_KEY>` with the key from Step 2:

```bash
curl -s -X POST http://localhost/graphql \
  -H "Content-Type: application/json" \
  -H "X-Kanvas-Key: <APP_KEY>" \
  -d '{
    "query": "mutation Register($data: RegisterInput!) { register(data: $data) { user { id uuid email default_company } token { token refresh_token token_expires } } }",
    "variables": {
      "data": {
        "firstname": "Admin",
        "lastname": "User",
        "displayname": "admin",
        "email": "admin@localtest.com",
        "password": "Test1234!",
        "password_confirmation": "Test1234!",
        "company_name": "Local Test Company"
      }
    }
  }' | python3 -m json.tool 2>/dev/null || \
curl -s -X POST http://localhost/graphql \
  -H "Content-Type: application/json" \
  -H "X-Kanvas-Key: <APP_KEY>" \
  -d '{"query":"mutation { register(data: { firstname: \"Admin\", lastname: \"User\", displayname: \"admin\", email: \"admin@localtest.com\", password: \"Test1234!\", password_confirmation: \"Test1234!\" }) { user { id email } token { token } } }"}'
```

Save from the response:
- `user.id` — the admin user ID
- `token.token` — Bearer token for authenticated requests
- `user.default_company` — the auto-created company ID

---

## Step 4 — Verify entities were created

```bash
docker exec phpkanvas-ecosystem php artisan tinker --execute="
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;

\$app = Apps::latest()->first();
echo '=== App ===' . PHP_EOL;
echo 'Name: ' . \$app->name . PHP_EOL;
echo 'Key:  ' . \$app->key . PHP_EOL;

\$user = Users::getByEmail('admin@localtest.com');
echo PHP_EOL . '=== User ===' . PHP_EOL;
echo 'ID:    ' . \$user->id . PHP_EOL;
echo 'Email: ' . \$user->email . PHP_EOL;

\$company = Companies::find(\$user->default_company);
echo PHP_EOL . '=== Company ===' . PHP_EOL;
echo 'Name: ' . \$company->name . PHP_EOL;

\$branch = CompaniesBranches::where('companies_id', \$company->id)->first();
echo PHP_EOL . '=== Branch ===' . PHP_EOL;
echo 'Name: ' . \$branch->name . PHP_EOL;
" 2>/dev/null
```

---

## Step 5 — Report credentials to the user

Present a summary block they can copy into `.env` or pass to the admin UI:

```
Environment ready
─────────────────────────────────────────
App Name:    Local Test App
App Key:     <key>          ← X-Kanvas-Key header / NEXT_PUBLIC_KANVAS_KEY
─────────────────────────────────────────
Admin Email:    admin@localtest.com
Admin Password: Test1234!
Bearer Token:   <token>     ← Authorization: Bearer <token>
─────────────────────────────────────────
Company:  Local Test Company (id: N)
Branch:   Default Branch    (id: N)
─────────────────────────────────────────
```

---

## Troubleshooting

| Error | Cause | Fix |
|---|---|---|
| `No query results for model [Users]` on app create | No users seeded yet | Run `docker exec phpkanvas-ecosystem php artisan db:seed` first |
| `Email has already been taken` | User registered in this app already | Change the email or use a different app key |
| `422 / validation error` on register | `displayname` already taken globally | Change the displayname |
| `Role not found` | Roles not created | Run `docker exec phpkanvas-ecosystem php artisan kanvas:create-role Admin` |
| GraphQL returns `Unauthenticated` | Wrong or missing `X-Kanvas-Key` | Double-check the key from Step 2 |
| `curl: connection refused` | API not running | Make sure Docker containers are up: `docker compose up -d` |
