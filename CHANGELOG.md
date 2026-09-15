# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-15

First public release. Verified on live Gnuboard7 7.0.11 sites (Kakao and Google sign-in end to end).

### Added

- **Kakao and Google login.** Sign in or sign up from the login page with a Kakao or Google account.
- **Auto-link to existing members.** When the social account's email matches a member whose email is
  verified, the account is linked to that member and the member is notified (mail + in-site
  notification). Matches against an unverified email are refused to prevent account takeover.
- **Link / unlink from the profile page.** Members manage their linked social accounts under
  `/mypage/profile`. Unlinking is blocked when it would leave the member with no way to sign in.
- **Admin settings per provider.** Enable Kakao / Google and manage client ID and client secret
  (secrets stored encrypted, masked in the admin screen).
- **Profile editing for social-only members.** Members who signed up with a social account (and have
  no password they know) can open the profile edit form without the password check. Members with a
  real password still get the check.
- **Default role for new sign-ups.** New social members receive the same default role (`user`) as the
  core registration, so they have regular member permissions (reading and writing posts, etc.).
- **Open-redirect protection.** The post-login return path accepts only same-site relative paths;
  anything else falls back to `/`.

### Notes

- Packages already provided by the Gnuboard7 core are declared in `composer.json` → `replace`, so the
  plugin's `vendor/` holds only the 8 plugin-specific packages. When the core moves to a new Laravel
  major version, upgrade `laravel/socialite` as well.
- Servers that cannot run Composer should install from the release zip, which includes `vendor/`.
- Known limitation: social-only members cannot set a password; their recovery path is the linked
  social account.

[1.0.0]: https://github.com/William1607cho/g7-social_login/releases/tag/v1.0.0
