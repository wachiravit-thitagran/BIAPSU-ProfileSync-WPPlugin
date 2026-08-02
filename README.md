# BIA PSU ProfileSync

A WordPress plugin that intercepts the **first-time login** completed through
[Authorizenter](https://github.com/wachiravit-thitagran/Authorizenter) and asks
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

The plugin uses an API Key to authenticate requests to the platform's profile
endpoint.

### Expected platform contract

**Profile** — `GET` with `Authorization: Api-Key <api_key>`:

```
<profile_endpoint>?email=<email>
→ 200 { "found": true, "profile": { "first_name": "...", "last_name": "...",
        "phone_number": "...", "affiliation": "...", "department": "...",
        "position": "...", "location": "...", "user_type": "...",
        "user_type_description": "...", "join_reason": "..." } }
→ 404 { "found": false }
```

> The platform here is the Django *volunteer-digitizing-app*. Expose a small
> DRF view that authenticates the API key and returns the
> `Volunteer` fields above, filtered by `email`.

## Field mapping

| Group | Platform field → WordPress |
|-------|----------------------------|
| Name | `first_name`, `last_name` → core fields + `display_name` |
| Contact | `phone_number` → user meta `biapsu_phone_number`; `email` → only if WP email empty |
| Affiliation | `affiliation`, `department`, `position`, `location` → `biapsu_*` user meta |
| User type | `user_type`, `user_type_description`, `join_reason` → `biapsu_*` user meta |

Each group can be toggled in **ProfileSync**.

## Installation

1. Copy/symlink this folder into `wp-content/plugins/biapsu-profilesync`.
2. Activate **Authorizenter Core** first, then **BIA PSU ProfileSync**.
3. Log in to your WordPress Admin.
4. Go to **ProfileSync** in the left sidebar menu.
5. Under **Platform Connection**, configure the API endpoints and the **API Key** for server-to-server communication.
6. Click **Test connection to the platform** to ensure your credentials are valid.

## Updates

The plugin updates **itself** from this repository's **GitHub Releases** — the
WordPress *Plugins* screen shows an available update whenever a release tag newer
than the installed version exists, and installs it like any other plugin.

- The release workflow (`.github/workflows/release.yml`) builds and attaches
  `biapsu-profilesync.zip` on every push to `main`. The updater prefers that
  asset over GitHub's source zipball, so the installed folder stays
  `biapsu-profilesync`.
- The default repository is `wachiravit-thitagran/BIAPSU-ProfileSync-WPPlugin`,
  and it must match the `Plugin URI` header — a typo there fails silently,
  because the GitHub API answers `404` exactly as it would for a repository with
  no releases. `GithubUpdaterTest` pins the two together.
- Release lookups are cached for six hours (a failure for one), so a private
  repository, a rate limit or an offline site costs nothing on the Plugins
  screen and never raises a notice.

Point it at a fork in `wp-config.php`, or filter it:

```php
define( 'BIAPSU_PROFILESYNC_GITHUB_REPO', 'your-org/your-repo' ); // '' disables updates
add_filter( 'biapsu_profilesync_github_repo', fn() => 'your-org/your-repo' );
```

## Extensibility

| Hook | Type | Purpose |
|------|------|---------|
| `biapsu_profilesync_should_prompt` | filter | Enable/disable the prompt per user at runtime. |
| `biapsu_profilesync_defer_to_questions` | filter | Override whether to wait for Authorizenter's required questions. |
| `biapsu_profilesync_platform_profile` | filter | Modify the raw profile array from the platform. |
| `biapsu_profilesync_github_repo` | filter | Repository (`owner/repo`) checked for updates; `''` disables them. |
| `biapsu_profilesync_github_request_args` | filter | `wp_remote_get()` args for the GitHub API (e.g. add an auth token). |
| `biapsu_profilesync_applied` | action | Fires after fields are applied (`$user, $profile, $applied`). |
| `biapsu_profilesync_decided` | action | Fires after the decision (`$user, 'sync'|'skip'`). |
| `biapsu_profilesync_finish_url` | filter | Change the final redirect after the decision. |

## Security notes

- **API Key:** API keys are encrypted before being saved to the WordPress database. They are only decrypted in-memory during a request.
- The decision form is nonce-protected and bound to the current user ID.
- Email sync never overwrites a non-empty WP email (prevents account hijacking).

## Development & testing

Pure PHPUnit unit tests with in-memory WordPress stubs (no live WordPress
needed), mirroring the Authorizenter setup.

```bash
composer install
composer test      # PHPUnit
composer lint      # PHPCS (WordPress Coding Standards)
composer analyze   # PHPStan (level 5)
composer syntax    # php -l on all files
```

GitHub Actions (`.github/workflows/ci.yml`) runs four jobs on every push/PR:
PHP syntax (8.0–8.3), PHPCS, PHPStan, and PHPUnit (8.0/8.2/8.3).

## License

GPL-2.0-or-later.
