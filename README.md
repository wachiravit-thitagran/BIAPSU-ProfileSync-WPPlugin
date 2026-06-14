# BIA PSU ProfileSync

A WordPress plugin that intercepts the **first-time login** completed through
[Authorizenter](https://github.com/wachiravit-thitagarn/authorizenter) and asks
the user whether to sync their profile from the **Buddhadhamma (พุทธธรรม)
platform**.

> ท่านเคยลงทะเบียนแพลตฟอร์มพุทธธรรมแล้ว ต้องการซิงค์ข้อมูลจากแพลตฟอร์มหรือไม่

- **Sync** → fetch `first_name`, `last_name` and other selected fields from the
  platform (server-to-server OAuth2) and apply them to the new WordPress user.
- **Skip** → keep the standard Authorizenter flow and data untouched.

Only new users ever see the prompt.

## How it works

| Step | Hook / mechanism | What happens |
|------|------------------|--------------|
| 1. Provision | `authorizenter_user_provisioned` | New user tagged `await`; login email stored. |
| 2. Arm at login | `authorizenter_login_success` | Evaluated **once**: if no required questions remain, arm now (`ready`); else stay `await`. |
| 3. Arm after questions | `authorizenter_questions_completed` | Fired by Authorizenter when the question form is finished → arm (`ready`). |
| 4. Gate | `template_redirect` @ priority **20** | `ready` users are diverted to the choice page; original destination remembered. Reads our own flag only. |
| 5. Decision | `admin-post.php` (`biapsu_profilesync_decision`, nonce-protected) | `sync` fetches + applies the platform profile; `skip` keeps existing data. Then continue to the original destination. |

Because the WP user is already created by Authorizenter, **Skip** is a true
no-op — the original flow is fully preserved. **Sync** enriches/overwrites the
selected fields with the richer platform data.

### Plays nicely with Authorizenter's question form

ProfileSync is **event-driven**: it only arms the sync prompt once Authorizenter
reports the question form is done, via the official
`authorizenter_questions_completed` action. (At login it also checks
`Questions::has_pending_required()` a single time, so users with no questions are
armed immediately.) The `template_redirect` gate then merely reads our own
`ready` flag — it never polls the question state on every page load. Ordering is
always:

```
login → Authorizenter question form (if any) → ProfileSync sync prompt → destination
```

The question form is never intercepted or broken — the sync prompt is chained
after it. The login-time evaluation can be overridden with the
`biapsu_profilesync_defer_to_questions` filter.

## Server-to-server data fetch

The plugin uses the OAuth2 `client_credentials` grant against the platform's
token endpoint to obtain an application access token (cached in a transient),
then calls the profile endpoint with the user's email.

### Expected platform contract

**Token** — `POST` (`application/x-www-form-urlencoded`), HTTP Basic client auth:

```
grant_type=client_credentials&scope=<scope>
→ 200 { "access_token": "...", "token_type": "Bearer", "expires_in": 3600 }
```

**Profile** — `GET` with `Authorization: Bearer <token>`:

```
<profile_endpoint>?email=<email>
→ 200 { "found": true, "profile": { "first_name": "...", "last_name": "...",
        "phone_number": "...", "affiliation": "...", "department": "...",
        "position": "...", "location": "...", "user_type": "...",
        "user_type_description": "...", "join_reason": "..." } }
→ 404 { "found": false }
```

> The platform here is the Django *volunteer-digitizing-app*. Expose a small
> DRF/OAuth-toolkit view that authenticates the bearer token and returns the
> `Volunteer` fields above, filtered by `email`.

## Field mapping

| Group | Platform field → WordPress |
|-------|----------------------------|
| Name | `first_name`, `last_name` → core fields + `display_name` |
| Contact | `phone_number` → user meta `biapsu_phone_number`; `email` → only if WP email empty |
| Affiliation | `affiliation`, `department`, `position`, `location` → `biapsu_*` user meta |
| User type | `user_type`, `user_type_description`, `join_reason` → `biapsu_*` user meta |

Each group can be toggled in **Settings → ProfileSync**.

## Installation

1. Copy/symlink this folder into `wp-content/plugins/biapsu-profilesync`.
2. Activate **Authorizenter Core** first, then **BIA PSU ProfileSync**.
   (Activation auto-creates a "Sync your profile" page with the
   `[biapsu_profilesync]` shortcode.)
3. Configure the platform connection under **Settings → ProfileSync** and run
   **Test connection**.

## Extensibility

| Hook | Type | Purpose |
|------|------|---------|
| `biapsu_profilesync_should_prompt` | filter | Enable/disable the prompt per user at runtime. |
| `biapsu_profilesync_defer_to_questions` | filter | Override whether to wait for Authorizenter's required questions. |
| `biapsu_profilesync_platform_profile` | filter | Modify the raw profile array from the platform. |
| `biapsu_profilesync_applied` | action | Fires after fields are applied (`$user, $profile, $applied`). |
| `biapsu_profilesync_decided` | action | Fires after the decision (`$user, 'sync'|'skip'`). |
| `biapsu_profilesync_finish_url` | filter | Change the final redirect after the decision. |

## Security notes

- The client secret is stored AES-256-CBC encrypted (key derived from WP salts).
- The access token is cached only in a transient, refreshed a minute early.
- The decision form is nonce-protected and bound to the current user ID.
- Email sync never overwrites a non-empty WP email (prevents account hijacking).

## License

GPL-2.0-or-later.
