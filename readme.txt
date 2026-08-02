=== BIA PSU ProfileSync ===
Contributors: biapsu
Tags: login, oauth, profile, sync, authorizenter
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

After a first-time login via Authorizenter, offer to sync the user's profile from the Buddhadhamma (พุทธธรรม) platform.

== Description ==

BIA PSU ProfileSync hooks into the Authorizenter login flow. The first time a
user is provisioned, they are shown a choice page:

"ท่านเคยลงทะเบียนแพลตฟอร์มพุทธธรรมแล้ว ต้องการซิงค์ข้อมูลจากแพลตฟอร์มหรือไม่"

* **Sync** — the plugin performs a server-to-server OAuth2 (`client_credentials`)
  call to the platform, looks the member up by email, and applies first name,
  last name and other selected fields to the new WordPress user.
* **Skip** — the normal Authorizenter flow and data are preserved.

Only brand-new users see the prompt; returning users are never interrupted.

== Requirements ==

* Authorizenter Core (provides the `authorizenter_user_provisioned` and
  `authorizenter_post_login_redirect` hooks).
* A platform OAuth2 application supporting the `client_credentials` grant, plus a
  profile-lookup endpoint that accepts `?email=` and returns JSON.

== Configuration ==

Settings → ProfileSync:

1. Enter the platform base URL (token/profile endpoints can be derived) or set
   each endpoint explicitly.
2. Enter the client ID and client secret (secret stored encrypted).
3. Choose which field groups to sync.
4. Use **Test connection** to confirm a token can be obtained.

== Updates ==

The plugin updates itself from this repository's GitHub Releases: the WordPress
Plugins screen offers a new version whenever a release tag newer than the
installed version exists, and installs the release's `biapsu-profilesync.zip`
asset (built by `.github/workflows/release.yml`), so the plugin folder name is
preserved.

The repository is `wachiravit-thitagran/BIAPSU-ProfileSync-WPPlugin` by default.
Point it elsewhere by defining `BIAPSU_PROFILESYNC_GITHUB_REPO` in `wp-config.php`
or filtering `biapsu_profilesync_github_repo` (return `''` to switch updates off).
Private repositories and higher rate limits need an authorization header, which
`biapsu_profilesync_github_request_args` can add. Release lookups are cached for
six hours; a failed or 404 lookup is cached for one hour and simply offers no
update.

== Platform endpoint contract ==

Token (POST, `application/x-www-form-urlencoded`, HTTP Basic client auth):

    grant_type=client_credentials&scope=<scope>
    -> 200 { "access_token": "...", "token_type": "Bearer", "expires_in": 3600 }

Profile (GET, `Authorization: Bearer <token>`):

    <profile_endpoint>?email=<email>
    -> 200 { "found": true, "profile": { "first_name": "...", "last_name": "...", ... } }
    -> 404 { "found": false }

== Changelog ==

= 0.1.2 =
* Self-hosted updates from this repository's GitHub Releases, so sites can be
  told a new version exists. Configurable with `BIAPSU_PROFILESYNC_GITHUB_REPO` /
  `biapsu_profilesync_github_repo`; the plugin details screen now shows this
  changelog and the full description.
* Fixed the misspelled owner in the `Plugin URI` header (a dead link, and the
  repository the updater derives from it).

= 0.1.0 =
* Initial release.
