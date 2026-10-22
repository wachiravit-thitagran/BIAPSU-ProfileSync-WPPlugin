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
