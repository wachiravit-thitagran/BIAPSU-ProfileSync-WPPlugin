=== BIA PSU ProfileSync ===
Contributors: biapsu
Tags: login, oauth, volunteer, identity, authorizenter
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks volunteer membership after verified Google login via Authorizenter, without importing any personal profile data.

== Description ==

On successful Google sign-in, check the existing BIA platform API by verified email.
When that email matches an existing volunteer, save the existing Authorizenter
"is_bia_volunteer" question as "ใช่" without showing another form.

When the platform explicitly responds "Volunteer not found", let Authorizenter
show the existing required Yes/No question. Keep previously recorded answers.
Missing API Key or API errors are unknown, not negative: skip the question and
allow redirect to the original return_to or home, then retry next Google login.

No profile sync or separate consent page is presented, and Tutor LMS is unchanged.
Other sign-in providers are unaffected.

== Configuration ==

WP Admin -> Volunteer Check:
1. Set platform base URL https://bia.psu.ac.th
2. Set API Key from the platform (kept encrypted in WordPress).
3. Use Test connection, and keep Verify SSL enabled.

The existing /api/profile/ endpoint requires Authorization: Api-Key and returns
200 { "success": true, "data": { "email": "..." } } or
400 { "success": false, "message": "Volunteer not found" }.

The Authorizenter question is_bia_volunteer must offer "ใช่" and "ไม่ใช่".

== Updates ==

GitHub Releases provide versioned biapsu-profilesync.zip assets. The plugin
slug and settings option remain unchanged so existing sites can upgrade.

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
