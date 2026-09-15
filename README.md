# g7-social_login

Kakao and Google social login for [Gnuboard7](https://github.com/gnuboard/g7).

- **Sign in / sign up** with a Kakao or Google account from the login page.
- **Auto-link** — if the social account's email matches an existing member whose email is verified,
  the social account is linked to that member instead of creating a new one (the member is notified).
- **Link / unlink** social accounts from the member profile page (`/mypage/profile`).
- **Admin settings** — enable each provider and manage its client ID / secret
  (secrets are stored encrypted and masked in the admin screen).
- **Social-only members can edit their profile** — the core profile-edit screen asks for the current
  password first; members who signed up with a social account have no password they know, so the
  plugin lets them skip that step. Members with a real password keep the password check.
- **Default role** — new social sign-ups get the same default role (`user`) as the core registration.
- **Open-redirect protection** — the post-login return path only accepts same-site relative paths.

The core and templates are not modified. The login / profile UI is injected through the
`core.layout_extension.after_apply` hook.

한국어 안내는 [아래](#사용법-한국어)에 있습니다.

## Requirements

- Gnuboard7 `>= 7.0.10` (tested on **7.0.11**)
- PHP `^8.2`
- A template whose login and profile layouts follow the `sirsoft-basic` structure
  (tested with `sirsoft-basic` and `wc-community`). If the anchor nodes cannot be found, the plugin
  logs an error and leaves the page unchanged.
- HTTPS site URL. Behind a reverse proxy, configure `TRUSTED_PROXIES` so the generated callback URL is
  `https://…` — providers reject a redirect URI that does not match exactly.

## Installation

The plugin depends on `laravel/socialite` and `socialiteproviders/kakao`. Packages that the Gnuboard7
core already ships (`illuminate/*`, `symfony/*`, `guzzlehttp/*`, `psr/*`, `nesbot/carbon`, …) are
declared in `composer.json` → `replace`, so only 8 plugin-specific packages end up in the plugin's
`vendor/` (about 5 MB) and the core's framework classes are always used at runtime.

**Where does `vendor/` come from?** The core installer runs
`composer install --no-dev` for the plugin when Composer can be executed on the server
(`proc_open` available and a composer binary found). If it cannot, the installer only logs a warning and
**finishes anyway** — the plugin then activates without its dependencies and the login buttons fail
with `provider_unavailable`. Pick the option that matches your server:

### Option A — release zip (works with or without Composer) — recommended

Download `g7-social_login-v1.0.0.zip` from the
[Releases](https://github.com/William1607cho/g7-social_login/releases) page. It contains the
plugin **with a prebuilt `vendor/` and `composer.lock`**.

```bash
# extract so that the folder becomes plugins/_pending/g7-social_login
unzip g7-social_login-v1.0.0.zip -d plugins/_pending/
php artisan extension:update-autoload
php artisan plugin:install g7-social_login
php artisan plugin:activate g7-social_login
php artisan hooks:cache
php artisan template:cache-clear
```

If Composer is available the installer re-runs `composer install --no-dev` from the bundled lock file;
otherwise the bundled `vendor/` is used as-is.

### Option B — git clone (requires Composer on the server)

```bash
git clone https://github.com/William1607cho/g7-social_login.git plugins/_pending/g7-social_login
# optional: install dependencies yourself instead of relying on the installer
(cd plugins/_pending/g7-social_login && composer install --no-dev)
php artisan extension:update-autoload
php artisan plugin:install g7-social_login
php artisan plugin:activate g7-social_login
php artisan hooks:cache
php artisan template:cache-clear
```

Verify after installing: `plugins/g7-social_login/vendor/laravel/socialite` must exist.

## Configuration

Open **Admin → Plugins → Social Login → Settings** (`/admin/plugins/g7-social_login/settings`),
turn a provider on and enter its client ID and client secret. Both values are required.

Callback (redirect) URIs to register with each provider — replace the domain with yours:

| Provider | Redirect URI |
|---|---|
| Kakao | `https://your-domain.com/api/plugins/g7-social_login/kakao/callback` |
| Google | `https://your-domain.com/api/plugins/g7-social_login/google/callback` |

### Kakao

1. Create an app at [Kakao Developers](https://developers.kakao.com/console/app).
2. Switch the app to a **Biz app** — required to make the Kakao account email a *required* consent item.
3. **[앱] > [플랫폼 키] > [REST API 키]**
   - REST API key → plugin **Client ID**
   - **[클라이언트 시크릿]** code → plugin **Client Secret** (enabled by default for new keys)
   - **[리다이렉트 URI]** → register the Kakao redirect URI above
4. **[카카오 로그인] > [사용 설정]** → set the status to **ON**.
5. **[카카오 로그인] > [동의항목]** → set **닉네임** and **카카오계정(이메일)** to *required*.

The email is used only when Kakao reports it as valid and verified; otherwise the member gets a
placeholder address (`social-…@no-email.invalid`) and cannot be auto-linked by email.

### Google

1. Create a project in [Google Cloud Console](https://console.cloud.google.com/).
2. Configure the **OAuth consent screen**. Under authorized domains, registering the top-level domain
   (e.g. `example.com`) also covers its subdomains.
3. **Credentials → Create credentials → OAuth client ID** → application type **Web application**.
4. Add the Google redirect URI above to **Authorized redirect URIs**.
5. Scopes: `openid`, `email`, `profile` (the plugin requests exactly these).

Brand verification of the consent screen only controls whether your logo is shown there; sign-in works
without it.

## How accounts are matched

On callback the plugin looks for, in order:

1. an existing link for this provider account → sign in as that member;
2. a member with the same **verified** email → link and sign in (a notification is sent);
3. a member with the same **unverified** email → refuse (prevents account takeover; sign in with the
   password and link manually from the profile page);
4. otherwise → create a new member with a random password, mark the email verified, assign the
   default `user` role and link the account.

Unlinking is blocked when it would remove the member's last way to sign in (no real password and no
other linked provider).

## Known limitations

- **Social-only members cannot set a password.** The core password-change API requires the current
  password (`current_password`), which these members do not know, so their only recovery path is
  the linked social account. Removing that rule would let anyone holding a stolen login token set a
  password and take over the account, so it is intentionally left in place.
- The login / profile UI is injected into the template's layout tree; a template whose layouts differ
  from `sirsoft-basic` will not show the buttons (an error is logged).

## Maintenance notes

- **When the Gnuboard7 core moves to a new Laravel major version, upgrade `laravel/socialite` (and
  `socialiteproviders/manager`) as well.** `replace` only stops duplicate installation; it does not
  guarantee compatibility. The plugin runs against the core's framework classes, so a core upgrade
  outside socialite's constraints (currently `illuminate/*` `^6`–`^13`, manager `^11`–`^13`,
  guzzle `^6`–`^8`) breaks login. Also re-check the `replace` list against the core's `vendor/` for
  newly overlapping packages.
- After regenerating `vendor/` on a running site, restart long-running PHP workers
  (`queue:work`, `reverb:start`) — they keep the old autoloader class map in memory.

## Uninstalling

```bash
php artisan plugin:deactivate g7-social_login
php artisan plugin:uninstall g7-social_login
```

The plugin tables (`g7_social_login_accounts`, `g7_social_login_link_nonces`,
`g7_social_login_user_flags`) are dropped only when you choose to delete plugin data.
Members created through social sign-up remain as regular members.

## 사용법 (한국어)

### 무엇을 하나요
그누보드7에 카카오·구글 소셜 로그인을 추가합니다. 코어와 템플릿은 수정하지 않습니다.
- 로그인 화면에 카카오·구글 버튼 추가, 소셜 계정으로 로그인·가입
- 인증된 이메일이 같은 기존 회원이 있으면 자동 연동(회원에게 알림 발송), 미인증 이메일은 거부
- 마이페이지에서 소셜 계정 연동·해제
- 관리자 화면에서 제공자별 활성화와 키 관리(시크릿은 암호화 저장)
- 소셜 가입자는 비밀번호 확인 없이 프로필 편집 가능
- 신규 소셜 가입자에게 코어 회원가입과 같은 기본 역할(`user`) 부여
- 로그인 후 이동 경로는 사이트 내부 상대경로만 허용(오픈 리다이렉트 방지)

### 설치
Composer를 실행할 수 없는 서버(공유 호스팅 등)에서는 **반드시 릴리즈 zip(vendor 포함)** 을 쓰세요.
git 저장소에는 `vendor/`가 없고, Composer가 없으면 설치기가 경고만 남기고 설치를 끝내 버려
로그인 버튼이 동작하지 않습니다.

```bash
unzip g7-social_login-v1.0.0.zip -d plugins/_pending/
php artisan extension:update-autoload
php artisan plugin:install g7-social_login
php artisan plugin:activate g7-social_login
php artisan hooks:cache
php artisan template:cache-clear
```

설치 후 `plugins/g7-social_login/vendor/laravel/socialite` 가 있는지 확인하세요.

### 설정
관리자 → 플러그인 → 소셜 로그인 설정(`/admin/plugins/g7-social_login/settings`)에서 제공자를 켜고
Client ID·Client Secret을 입력합니다. 리다이렉트 URI는
`https://도메인/api/plugins/g7-social_login/{kakao|google}/callback` 입니다. 리버스 프록시 뒤라면
`TRUSTED_PROXIES`를 설정해 콜백 URL이 `https://`로 만들어지게 하세요(글자 하나라도 다르면 거부됩니다).

**카카오**
1. [카카오디벨로퍼스](https://developers.kakao.com/console/app)에서 앱 생성
2. **비즈 앱 전환** — 카카오계정(이메일)을 필수 동의로 받으려면 필요
3. **[앱] > [플랫폼 키] > [REST API 키]** — REST API 키(= Client ID), [클라이언트 시크릿] 코드
   (= Client Secret, 기본 활성화), [리다이렉트 URI] 등록
4. **[카카오 로그인] > [사용 설정]** — 상태 ON
5. **[카카오 로그인] > [동의항목]** — 닉네임·카카오계정(이메일) 필수 동의

**구글**
1. [Google Cloud Console](https://console.cloud.google.com/)에서 프로젝트 생성
2. OAuth 동의 화면 구성 — 승인된 도메인은 최상위 도메인만 등록하면 서브도메인 포함
3. 사용자 인증 정보 → OAuth 클라이언트 ID → 애플리케이션 유형 **웹 애플리케이션**
4. 승인된 리디렉션 URI에 구글 콜백 주소 등록
5. 범위: `openid`, `email`, `profile`

동의 화면 브랜딩 인증은 로고 표시용 절차일 뿐 로그인 기능과 무관합니다.

### 알려진 제약
- **소셜 가입자는 비밀번호를 설정할 수 없고, 계정 복구 수단이 연동된 소셜 계정뿐입니다.** 코어 비밀번호
  변경 API가 현재 비밀번호를 서버에서 요구하기 때문이며, 이 규칙을 풀면 로그인 토큰 탈취자가 비밀번호를
  설정해 계정을 장악할 수 있어 의도적으로 열지 않았습니다.
- 로그인·마이페이지 화면 주입은 `sirsoft-basic` 계열 레이아웃 구조에 의존합니다.

### 유지보수 주의사항
- **코어가 Laravel 메이저 버전을 올리면 socialite도 함께 올려야 합니다.** `replace`는 중복 설치만 막을 뿐
  호환성을 보장하지 않습니다. 코어 업그레이드 시 `replace` 목록도 코어 `vendor/`와 다시 대조하세요.
- 운영 중 `vendor/`를 다시 만들었다면 `queue:work`·`reverb:start` 같은 장기 실행 워커를 재시작하세요.

## Development log

The detailed development history (investigations, root causes, and verification of each fix) is kept
in [docs/DEVELOPMENT_LOG.md](docs/DEVELOPMENT_LOG.md) (Korean). Release history is in
[CHANGELOG.md](CHANGELOG.md).

## License

[MIT](./LICENSE) © 2026 William Cho
