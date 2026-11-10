# BIA PSU ProfileSync — Google Volunteer Question + OIDC Linking

The plugin no longer queries BIA Platform member profiles via API Key. Instead, it uses Authorizenter's existing required radio question `is_bia_volunteer` (ใช่/ไม่ใช่) when a user signs in through Google.

A **ไม่ใช่** answer continues to the original learning destination. A **ใช่** answer presents the **เชื่อมต่อบัญชีแพลตฟอร์มพุทธธรรม** button on the existing `[biapsu_profilesync]` page; linking is optional.

The button starts Authorizenter's existing `oidc` OAuth/OIDC flow. A nonce-authorized, time-limited intent is bound to the original WordPress auth cookie and the OAuth callback's authenticated return URL. The platform subject is linked to that same WordPress user only after a successful OIDC login. An OIDC subject already owned by another user, or a conflicting existing link, is rejected. No profile fields are imported.

## Configuration

Enable Authorizenter Google and OIDC providers in the default context. Configure the existing `is_bia_volunteer` question as required radio (ใช่/ไม่ใช่). Enable Account Linking in ProfileSync Admin. Keep the existing `/profile-sync/` page containing `[biapsu_profilesync]`. No Platform API Key is needed.

## MCP Abilities

- `biapsu-profilesync/get-status` (admin, read-only): enabled, flow, OIDC provider, choice page, no API dependency.
- `biapsu-profilesync/get-link-status` (self or user-listing administrator, read-only): volunteer answer and OIDC link status without disclosing subject IDs, credentials or tokens.
- `biapsu-profilesync/get-user-state`: legacy compatibility, read-only.

Starting the OAuth flow is intentionally a **browser-only POST with WordPress nonce and active authentication**. It is not a mutating MCP ability.

## Testing

Run `composer test`, `composer lint` and `composer analyze`. Verify the complete OAuth flow on staging with both matching and different provider emails, rejected conflicting subjects, cancel/failure paths, and original return destinations. **Do not deploy without an end-to-end browser test.**

Legacy ProfileSync profile-mapping code remains in the package but is not registered at runtime.

### Account linking compatibility and page recovery

This version needs Authorizenter Core's `User_Mapper::claim_link()` and
`authorizenter_pre_login_user` boundary. Explicit links are claimed after Core
policy/capability approval and before login cookies; conflicts, lock contention,
and persistence failures stop the login visibly. Normal Core verified-email
linking remains available for an unlinked account. It cannot replace an existing
different provider subject.

A callback carrying `biapsu_link` requires its matching unexpired intent and the
exact original valid WordPress login cookie. Missing, revoked, switched or renewed
cookies require restarting the linking flow. Ordinary OIDC sign-ins without the
link marker retain their normal behavior.

The choice page must be published, have page post type and contain the plugin
shortcode. Administration and Google routing recover unusable/missing pages
without editing unrelated slug-collision content. If creation or the default
context's OIDC provider configuration is unavailable, Google keeps its original
destination and administrators see a setup notice.
