# BIA PSU ProfileSync — Volunteer Status Check

This **existing WordPress plugin** now answers a single question during Authorizenter Google sign-in: `is_bia_volunteer` ("เคยเป็นอาสาสมัครบนแพลตฟอร์มพุทธธรรมหรือไม่?").

It does **not** synchronize profile fields, display a separate sync-consent form, or change Tutor LMS.

## New sign-in behavior

1. After a successful **Google** login, Authorizenter supplies a verified email.
2. If the user already answered `is_bia_volunteer`, keep their answer; no API request.
3. Otherwise, ProfileSync calls the existing Django `/api/profile/?email=...` endpoint with a server-side API key.
4. Explicit match for the *same verified email* → save `is_bia_volunteer=ใช่` via Authorizenter's Questions service and skip its question form.
5. Explicit `Volunteer not found` → let Authorizenter ask its existing **ใช่ / ไม่ใช่** question.
6. Missing API configuration, network error, unexpected HTTP response or email mismatch → record **unavailable**, never claim non-membership or block the learner. Retry at their next Google sign-in.

For non-Google providers, do not call the API and do not ask the Google-only volunteer question. Other Authorizenter questions remain unaffected.

Authorizenter retains all original `return_to`, context redirect, authentication, and WordPress session behavior. The fallback destination remains the site's home page.

The API response is consumed as a **boolean membership check**; no name, phone, affiliation, department, position, location, user type, or email changes are written to WordPress.

Existing `authorizenter_answers` values are preserved. Positive automatic answers also record `biapsu_volunteer_answer_source=platform_api`. The previous shortcode `[biapsu_profilesync]` remains as a harmless Continue link for old bookmarks, and legacy `biapsu_sync_state` flags are ignored.

## Deployment settings

In **WordPress Admin → Volunteer Check**:

- Enable volunteer status check.
- Set Platform base URL to `https://bia.psu.ac.th`.
- Leave Profile endpoint blank to use `https://bia.psu.ac.th/api/profile/`.
- Enter the platform's existing **API Key** securely in WordPress Admin; it is encrypted before storage.
- Keep TLS verification enabled; use **Test connection** to verify the API key and endpoint.

**Without an API key, automatic detection is unavailable.** Google sign-in remains accessible; no membership result is fabricated. Do not insert any secret in Git, chat or logs.

The platform endpoint contract is `GET /api/profile/?email=...` with `Authorization: Api-Key <secret>`. Only `200, success:true, data.email == verified Google email` proves membership. Only `400, success:false, message:"Volunteer not found"` proves an explicit negative. All other outcomes are **unknown**.

The Authorizenter question `is_bia_volunteer` must be enabled as a required radio question with exactly `ใช่` and `ไม่ใช่` values. The integration filters it by actual login provider, so it only interrupts Google sign-ins after a definite negative result.

## Testing

`composer test` for isolated PHP unit tests, `composer lint` for WordPress Coding Standards, and GitHub Actions CI/WP Integration for PHP 8.0–8.4 and clean WordPress plugin lifecycle.

The new runtime uses `Volunteer_Status` in `includes/class-volunteer-status.php` and `Platform_Client::check_membership()`. Legacy sync classes remain in the package for compatibility but their sync/consent hooks are not registered.

## Self-hosted updates

GitHub Releases in `wachiravit-thitagran/BIAPSU-ProfileSync-WPPlugin` produce `biapsu-profilesync.zip`. The plugin slug and existing settings option are unchanged to support upgrades.
