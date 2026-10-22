=== BIA PSU ProfileSync ===
Contributors: biapsu
Tags: login, oauth, volunteer, identity, authorizenter
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ask Google sign-in users whether they volunteer on the Buddhadhamma Platform, then offer optional secure OIDC account linking.

== Description ==

After Google login via Authorizenter, the existing required question
"is_bia_volunteer" asks "คุณเป็นอาสาสมัครบนแพลตฟอร์มพุทธธรรมหรือไม่?"
with radio answers "ใช่" and "ไม่ใช่". Existing answers remain unchanged.

"No" continues to the original destination. "Yes" presents a button to link the
Buddhadhamma Platform account via the existing Authorizenter "oidc" provider.
The link request requires the current browser's signed-in WordPress session
and a WordPress nonce. The OIDC callback must match the same pending OAuth
flow. A platform subject already connected to a different WordPress account
cannot be linked again. Different verified provider emails are supported
without creating another WordPress account or importing profile fields.

There is no Platform API Key requirement, profile lookup, or automatic
profile synchronization. Tutor LMS and other login providers are unchanged.

== Configuration ==

1. Enable Google and Buddhadhamma Platform OIDC providers in Authorizenter.
2. Enable required radio question is_bia_volunteer with "ใช่" / "ไม่ใช่".
3. In WP Admin -> Account Linking, enable the feature.
4. Keep the existing /profile-sync/ page with [biapsu_profilesync].
5. Test Google -> question -> OIDC consent linking end to end on staging.

The WordPress Abilities/MCP adapter exposes read-only get-status,
get-user-state (compatibility), and get-link-status abilities. OAuth
account linking must always happen interactively in the signed-in browser.

== Updates ==

GitHub Releases provide versioned biapsu-profilesync.zip assets. The plugin
slug and settings option remain unchanged so existing sites can upgrade.

== Changelog ==

= Unreleased =
* Replace platform API membership check with Google self-declaration.
* Add optional secure OIDC account linking and read-only link status MCP ability.

= 0.1.2 =
* Self-hosted GitHub Releases updates and plugin metadata improvements.

= 0.1.0 =
* Initial release.
