# AccessControlList — Bouncer scope and cache

Loads for work under `src/Kanvas/AccessControlList/` and anything that assigns or checks Bouncer roles.

## Every role write and read happens in a scope, and the scope is process state

Bouncer auto-appends `scope = <current>` to every role and ability query. The current scope is set by
`RolesMiddleware` from the request's app key, by `overwriteAppService($app)`, or by whatever ran last on
this worker. `$user->assign('Owner')` with no scope switch assigns in **whichever app the caller was in**,
not the app the role is meant for.

Write or read a role for a specific app only inside `Bouncer::scope()->onceTo(RolesEnums::getScope($app), ...)`.
For the Owner role use the one helper, `Users::assignOwnerOf($app)` (mirror of `Users::isOwnerOf($app)`),
instead of a third copy of that block.

Real incident (2026-10-16 → 2026-11-02): `CreateAppsAction` started restoring the caller's scope before
assigning Owner, so every app created in that window gave its creator Owner on the *origin* app and
nothing on the new one. `@guardByAdmin` then refused the creator on their own app. Repair snippet for
affected apps: `docs/ecosystem/assign-app-owner-tinker.md`.

## Bouncer's role cache is per scope, stored forever, and `assign()` never clears it

`Bouncer::cache()` is on (`AppServiceProvider`). `CachedClipboard` keys the cached roles/abilities by
`<tag>-<scope>-roles-<entity>` and stores them with no TTL in Redis. `AssignsRoles::to()` does not
invalidate anything. So a user denied once in a scope stays denied in that scope until something calls
`Bouncer::refreshFor($user)` **while that scope is current**. A `refreshFor` outside the `onceTo` clears
the wrong key and looks like it did nothing.

```php
// WRONG — the refresh runs in the caller's scope; the stale "no roles" entry for $app survives
Bouncer::scope()->onceTo(RolesEnums::getScope($app), fn () => $user->assign('Owner'));
Bouncer::refreshFor($user);

// CORRECT — assign and refresh under the same scope
Bouncer::scope()->onceTo(RolesEnums::getScope($app), function () use ($user) {
    $user->assign('Owner');
    Bouncer::refreshFor($user);
});
```

## Known gap, not fixed

`AssignCompanyAction` assigns the default Admin role to the `users_associated_apps` row, not to the
`Users` model, so `Users::isAdmin()` never sees it. The creator of a new app passes `isAdmin()` through
Owner only.
