# g7-social_login

## 목적
atozai.william-cho.com(그누보드7)에 카카오·구글 소셜 로그인을 커스텀 플러그인으로 추가.
기존 이메일 가입 회원과 이메일 일치 시 자동 연동(단, 이메일 인증된 계정만), 마이페이지에서
수동 연동/해제 지원.

## 시작일
2026-09-12

## 상태
진행중 — 카카오 실로그인 E2E 완료(2026-09-15, blog.william-cho.com). 구글은 OAuth 클라이언트
등록(윌리엄) 후 실로그인 E2E 대기. atozai 설치본은 9/15 수정 미반영.

## 레포명
https://github.com/William1607cho/g7-social_login (2026-09-12 push 완료)

## 브랜치명
main (feature 브랜치 없이 단일 브랜치로 개발 — 아직 비공개 초기 개발 단계)

## 관련 Flarum 링크
(미개설)

## 진행상황

### 2026-09-12 — 조사 + 설계 + 구현 + 설치/기능 테스트 완료 (직접 수행)
- **조사**: `auth/login` 레이아웃에 extension_point 없음 확인 → `core.layout_extension.after_apply`
  필터 훅(g7-forum-addon 선례)으로 코어/템플릿 무변경 주입 결정. `users.password` NOT NULL
  확인 → nullable 마이그레이션 대신 **랜덤 해시 비밀번호 + 곁다리 플래그 테이블**
  (`g7_social_login_user_flags.has_real_password`)로 처리(코어 스키마 무변경, 기존 코드
  블라스트 레이디어스 0). `email_verified_at` 존재 확인 → 자동연동은 인증된 이메일에만.
  Sanctum 토큰 발급 패턴(`AuthService::login` 동일 방식) 확인.
- **테스트 중 발견한 로컬 전용 스캐폴드**: `templates/sirsoft-basic/src/components/composite/SocialLoginButtons.tsx`
  가 이미 존재(카카오/구글/네이버/페이스북/애플 버튼 UI, `/api/auth/{provider}` 호출) —
  git 미추적(templates/*/ 전체 gitignore 대상) + 백엔드 라우트 0건 확인, 다음 `template:update`
  시 사라질 고아 코드로 판단해 재사용하지 않고 플러그인 자체 JSON 노드(Div/A 기본 컴포넌트)로
  버튼 구현.
- **설계**: `laravel/socialite` + `socialiteproviders/kakao`. `Socialite::buildProvider()`
  로 DB 저장 client_id/secret 직접 조립(코어 `config/services.php` 무변경). 로그인은
  OAuth 콜백 → Sanctum 토큰 발급 → **1회용 교환코드**(60초 TTL, 캐시)로 프론트에 전달
  (토큰을 리다이렉트 URL에 노출하지 않음) → 로그인 화면 init_action이 교환 후
  `saveToLocalStorage`+`setState currentUser`로 세션 확립(코어 `handler:"login"`은
  `/api/auth/login` 고정이라 재사용 불가, 순수 JSON만으로 우회). 마이페이지 "연동하기"는
  인증된 XHR로 **1회용 link nonce**(5분 TTL) 발급 → `openWindow target:_self`로 전체이동
  (Authorization 헤더가 실리지 않는 문제 우회).
- **네이밍 버그 실측 수정**: 최초 `Plugins\G7\Social\Login\` 네임스페이스가 g7 규칙
  (`g7-social_login` → 언더스코어는 PascalCase 결합) 위반으로 `Plugins\G7\SocialLogin\`
  이어야 함을 `plugin:install` 실패로 실측 발견·전면 수정.
- **레이아웃 훅 실측 함정**: `after_apply` 가 받는 최종 트리는 소스 JSON의
  `slots.content`/`init_actions` 가 아니라 **`components`(평탄화) / `initActions`
  (카멜케이스)** 로 이미 변환된 형태 — API 응답 직접 확인으로 발견·수정. `hooks:cache`
  재생성 누락 시 신규 플러그인 훅이 무시되는 함정도 재확인(g7 인프라 기존 지식과 일치).
- **`email_verified_at` 매스어사인먼트 버그 실측 수정**: `User::$fillable` 에 없어
  `User::create(['email_verified_at'=>...])` 가 조용히 무시됨 → 신규 소셜 가입자의
  이메일이 영원히 미인증 처리되어 이후 다른 프로바이더 자동연동이 막히는 결함을 실제
  테스트로 발견 → `forceFill()->save()` 로 수정.
- **DB 기능 테스트(tinker, 실제 DB에 임시 유저 생성 후 정리 완료)**: 신규 소셜 가입,
  인증된 이메일 자동연동, 미인증 이메일 자동연동 거부(계정탈취 방지), `canUnlink` 안전
  로직, 알림 발송(`g7-social_login.account.auto_linked` → GenericNotification, mail+database
  채널 각각 정상 발송, Redis 큐 비동기 처리 확인) 전부 정상 확인. 테스트 데이터 전량 정리 완료.
- **미완**: 실제 카카오/구글 OAuth 왕복(개발자 콘솔 앱 등록 필요, 윌리엄 담당) E2E 테스트,
  실브라우저 로그인 화면 버튼 육안 확인, GitHub 공개 배포 여부/시점 결정.

### 2026-09-12 (이어서) — 관리자 설정화면 404 수정 (직접 수행)
- **원인**: `resources/layouts/admin/plugin_settings.json`에 다른 모든 배포된
  플러그인(sirsoft-daum_postcode/gdpr/marketing/verification_kginicis/message_bizppurio
  6종 전수 대조 확인)이 공통으로 갖는 top-level 필드 3개
  (`layout_name: "plugin_settings"`, `permissions: ["core.plugins.update"]`,
  `extends: "_admin_base"`)가 빠져 있었음. `docs/extension/plugin-development.md`의
  "전체 예시: Daum 우편번호 플러그인" 스니펫을 그대로 따라 작성했는데, **그 문서
  예시 자체가 이 3개 필드를 생략한 축약본**이었던 게 근본 원인 — 실제 배포된
  daum_postcode 파일은 훨씬 풍부하고 이 필드들을 포함.
  `layout_name` 누락 시 DB 시딩 로직이 자체적으로 이름을 지어 붙여
  `g7-social_login.g7-social_login_admin_plugin_settings`로 등록됨(정상:
  `g7-social_login.plugin_settings`) → 프론트가 정상 이름으로 조회 시 404.
- **활성화 재시딩 여부**: 활성화(`plugin:activate`)는 실제로 레이아웃 등록을
  수행함("1개 레이아웃 등록됨" 출력) — 시더 자체가 안 돈 게 아니라 **소스 파일이
  잘못된 이름으로 등록되게 만들었을 뿐**. 재현/수정 둘 다 `plugin:refresh-layout
  g7-social_login`(빌드 없이 JSON만 DB 재동기화)로 처리, 전체 deactivate→activate
  불필요.
- **수정+검증**: 누락 필드 3개 추가 → `plugin:refresh-layout` 실행(로그: 생성 1,
  삭제 1 — 잘못된 이름 행 삭제, 올바른 이름 행 신규 생성) → DB에서
  `g7-social_login.plugin_settings`로 정확히 등록됨 확인 → 실제 브라우저가 치는
  것과 동일한 API(`/api/layouts/sirsoft-admin_basic/g7-social_login.plugin_settings.json`)
  직접 호출로 최초 에러 문구("Layout not found: template_id=1,
  name=g7-social_login.plugin_settings")를 그대로 재현한 뒤 200 정상 응답으로
  전환 확인. 스키마 콘텐츠 자체는 `/api/admin/plugins/g7-social_login/settings/layout`
  에서 정상 반환 확인(카카오/구글 6개 설정 필드 전부 포함).

### 2026-09-12 (이어서) — 설정화면 빈 화면 수정 (직접 수행)
- 404는 고쳤지만 화면 콘텐츠가 완전히 비어 보이는 2차 문제 발견. 원인:
  `docs/extension/plugin-development.md`의 "간소화 버전"(`schema` 키만
  정의하면 UI 자동생성)은 프론트/백엔드 어디에도 구현 안 된 문서상의 기능.
  `App\Services\LayoutService::mergeLayouts()`가 병합 대상 키를 명시적
  화이트리스트로 고르는데 `pageConfig`/`schema`는 그 목록에 없어 조용히
  버려지고, `_admin_base.json`의 콘텐츠 슬롯은 `slots.content`가 없으면
  `children: []`로 남아 에러 없이 아무것도 안 그림.
- 실제 배포된 모든 플러그인이 쓰는 방식대로 `slots.content`에 PageHeader/
  알림배너/폼필드(`dataKey`+`trackChanges` 자동바인딩)/가이드/저장버튼을
  명시적으로 작성하는 "커스텀 레이아웃" 방식으로 전면 재작성. API 응답으로
  content 트리에 필드 노드들이 실제로 채워짐을 확인(kakao_section,
  save_button 등 존재 확인).

### 2026-09-12 (이어서) — 설정 저장 무동작 수정 (직접 수행)
- **증상**: 저장 버튼 클릭 시 "성공" 메시지는 뜨지만 체크박스뿐 아니라 텍스트
  필드까지 전부 저장 안 되고 그대로 되돌아옴.
- **원인**: `UpdatePluginSettingsRequest::rules()`가 `$plugin->getSettingsSchema()`
  를 검증 규칙의 유일한 소스로 씀. `plugin.php`가 이 메서드를 오버라이드하지
  않아 `AbstractPlugin` 기본값(빈 배열)이 나감 → 검증 규칙 0개 →
  `$request->validated()`가 제출 필드를 전부 걸러냄 → `save()`에는 빈 배열이
  전달돼 기존 값 그대로 재기록(파일 쓰기는 성공하니 "성공" 메시지만 뜸).
  `config/settings/defaults.json`은 설치 시점 초기 시딩 + 프론트 노출
  (`frontend_schema`)에만 쓰이고, 저장/조회 시 검증·민감값 마스킹 경로는
  `plugin.php`의 PHP 메서드(`getSettingsSchema`/`getConfigValues`)를 별도로
  참조한다는 걸 놓쳤던 게 근본 원인 — 두 메커니즘이 독립적임.
- **부수 발견**: 스키마가 비면 `encryptSensitiveFields`/`decryptSensitiveFields`/
  `SensitiveSettingMask::stripUnchanged`가 전부 no-op라 민감 필드가 평문
  저장되고, 마스크 값(`••••••••`) 재제출 시 실제 비밀값을 덮어쓸 수 있는
  잠재 결함도 같이 있었음 — 이번 수정으로 함께 해소(그때까지 저장된 실제
  시크릿이 없어 데이터 유실은 없었음).
- **수정+검증**: `plugin.php`에 `getSettingsSchema()`/`getConfigValues()` 추가.
  직접 PUT/GET으로 재현: 수정 전(체크박스+텍스트 필드 전부 무저장) →
  수정 후(전부 정상 저장·조회, 시크릿 암호화 확인 `storage/app/plugins/
  g7-social_login/settings/setting.json`에서 암호문 형태 확인) → 로그인
  페이지 실제 HTML(`G7Config.plugins`)에 `kakao_enabled:true`가 즉시
  반영됨까지 확인 후 테스트값은 초기화(전부 false/빈값)로 되돌림.

### 2026-09-12 (이어서) — 카카오/구글 공식 브랜드 아이콘 적용 (직접 수행)
- **구글**: `developers.google.com/identity/branding-guidelines` 공식 배포
  ZIP(`signin-assets.zip`, 인증 불필요 공개 다운로드)에서 Android+Web
  PNG @2x, Theme=Light, Show text=No, Shape=Square 선택 — 흰 배경+테두리+
  컬러 G 로고, 텍스트 미포함 아이콘 전용 에셋을 **무변경(바이트 그대로)**
  사용.
- **카카오**: `developers.kakao.com/tool/resource/login`(공개, 로그인
  불필요 — 연결된 브라우저로 직접 확인)의 "완성형" 탭, 버튼 크기 Large+
  너비 Large 조합에서 노출되는 공식 PNG(`kakao_login_large_wide.png`,
  600x90)에서 말풍선 심볼 영역만 크롭 후 배경(#FEE500)을 투명 처리.
  **심볼 형태·비율은 원본 픽셀 그대로(재작도 없음)** — 이 도구 페이지에는
  심볼 단독 다운로드가 없어 부득이 크롭 방식 사용.
- `src/Support/BrandIcons.php` 신설(두 PNG를 런타임에 base64 data URI로
  인코딩, 별도 정적 자산 라우트 불필요 — 이 플러그인은 프론트 JS 번들이
  없음). 로그인 화면 버튼과 마이페이지 연동 행 양쪽에 동일 아이콘 적용.
- **검증**: 연결된 브라우저로 `atozai.william-cho.com/login`을 직접 열어
  두 버튼 모두 아이콘 정상 렌더 확인(카카오 검은 말풍선, 구글 컬러 G
  로고+테두리). 확인 후 테스트용 활성화 상태는 다시 꺼둠.

### 2026-09-12 (이어서) — GitHub 공개 저장소 push (직접 수행)
- push 전 전체 커밋 이력(`git log --all -p`)에서 `.env`/`SECRETS.local.md`/
  실제 시크릿 패턴 전수 스캔 — 발견 0건(테스트 중 사용한 임시 키값은 curl로
  서버 API에만 보냈을 뿐 어떤 커밋에도 포함된 적 없음을 확인).
  `git remote add origin https://github.com/William1607cho/g7-social_login.git`
  등록 후 `git push -u origin main` — 커밋 8개, 브랜치 `main`(처음부터
  이 저장소는 단일 main 브랜치로만 작업, 다른 g7-* 플러그인 저장소들과
  동일 관례). 원격 `refs/heads/main`이 로컬 HEAD(`247e7e8`)와 일치함을
  `git ls-remote`로 확인.

### 2026-09-13 — 마이페이지 연동 상태 미반영 버그 수정 (직접 수행)
- **증상**: 실제 연동 여부와 무관하게 카카오/구글 행에 상태 텍스트도
  버튼도 전혀 안 보임(윌리엄 관찰: "이미 연동된 것처럼" 보임 — 실제로는
  "연동하기" 버튼이 렌더 안 돼 아무 조작도 못 하는 상태였음).
- **원인**: 백엔드(`/api/plugins/g7-social_login/accounts`)는 처음부터
  정상 동작. 프론트 분기 로직도 존재했으나, `$isLinkedExpr` 변수를 이미
  `{{ }}`로 감싼 채 정의해두고 그걸 삼항식/부정 표현식에 재사용하면서
  `{{{{...}} ? 'a' : 'b'}}` 형태의 이중/분리된 중괄호로 깨진 바인딩이
  발생 — 파서가 평가 못 하고 침묵 실패(에러 없이 빈 값/false). 언링크
  버튼 하나만(단독 사용이라 안 깨짐) 정상 조건이었지만 링크 버튼 쪽이
  깨져서 결과적으로 두 버튼 다 안 보였음.
- **수정**: 순수 표현식 변수를 따로 두고 사용처마다 한 번만 `{{ }}`로
  감싸도록 전면 수정. 부수 반영: 섹션 제목 "연동된 소셜 계정"→"소셜로그인
  계정 관리", 버튼 문구 "(카카오) 연동하기"/"(카카오) 언링크하기" 형식으로
  변경. `canUnlink()` 등 서버측 안전장치는 무변경.
- **검증**: playwright 테스트 유저로 (1) 연동 0건 → 둘 다 정상 "연동하기"
  표시 확인(수정 전엔 미표시), (2) DB에 카카오 연동 레코드 직접 생성 후
  새로고침 → 카카오만 "언링크하기"(빨강), 구글은 "연동하기" 유지 — 목표
  시나리오와 정확히 일치. 검증 후 테스트 데이터 전량 정리, GitHub push
  완료(`8b640a4`).
- **윌리엄 실계정 재확인(같은 날)**: 확인창(native confirm)이 정상적으로
  뜨고, 카카오 "연동됨"+빨강 "(카카오) 언링크하기", 구글 "연동 안 됨"+
  "(Google) 연동하기"로 정확히 분기 표시됨을 직접 확인받음.

### 2026-09-13 (이어서) — 언링크/연동하기/로그인 교환 apiCall 404 수정 (직접 수행)
- **증상**: 위 확인 직후 윌리엄이 실제로 "(카카오) 언링크하기"를 클릭 →
  확인창 OK 이후 "The route plugins/g7-social_login/kakao/unlink could
  not be found." 에러 토스트.
- **원인**: `apiCall` 핸들러의 `target` 값에 axios baseURL이 자동으로
  `/api`를 붙여줄 거라고 잘못 가정 — 실제로는 `/api/...` 전체 경로를
  명시적으로 적는 것이 코드베이스 전체의 예외 없는 관례(수십 곳 대조
  확인). `data_source`(연동 목록 조회)는 처음부터 정확히 작성돼 있어
  멀쩡했지만, 이후 작성한 `apiCall` 3곳(link/prepare, unlink, 로그인
  화면의 교환코드 처리)만 이 관례를 놓쳐 전부 404 — exchange는 아직
  실제 앱 키가 없어 트리거된 적 없는 잠재 버그였고, unlink/link만
  윌리엄의 실제 클릭으로 표면화됨.
- **수정**: 세 target 모두 `/api/` 접두사 추가. 로그인 버튼 href·
  openWindow용 redirect_url·관리자 설정 저장 target은 처음부터 절대
  경로/올바른 접두사였음을 재확인.
- **검증**: 실제 프론트가 보내는 것과 동일한 메서드/경로로 직접 호출 —
  `POST /api/plugins/g7-social_login/kakao/link/prepare`,
  `DELETE .../kakao/unlink` 둘 다 200 확인. 테스트 데이터 정리, GitHub
  push 완료(`4899557`).

### 2026-09-15 — 카카오 로그인 진입 500 + 로그인 완료 불가 수정, 실로그인 E2E 완료 (직접 수행)
대상: blog.william-cho.com 신규 설치본(카카오 비즈앱·REST 키·Redirect URI·이메일 동의항목 설정 직후).
- **증상**: ① 로그인 화면 카카오 버튼 클릭 시 자체 500 페이지. 주소창은
  `.../kakao/redirect?redirect={{encodeURIComponent(query.redirect ?? '/')}}` (표현식 원문).
  ② 500 수정 후 카카오 동의→콜백까지 성공했으나 `/login?social_exchange=...` 에서 로그인
  화면이 그대로 다시 뜸. ③ 그 수정 후 홈으로 이동은 되나 새로고침 전까지 비로그인으로 표시.
- **원인** (전부 코드·로그로 확정, 추측 없음):
  - ① 500 = `redirect` 파라미터와 무관. laravel.log 예외
    `BindingResolutionException: Target [Laravel\Socialite\Contracts\Factory] is not instantiable`
    (`SocialAuthService::driverFor` 의 `Socialite::buildProvider`). socialite 가 플러그인 자체
    vendor 에 있어 코어 패키지 auto-discovery 대상이 아니므로 `SocialiteServiceProvider` 미등록 →
    파사드 해석 실패. 컨트롤러는 `RuntimeException` 만 잡아 500. 그 다음 단계에 잠복 결함 하나 더:
    import 한 `SocialiteProviders\Kakao\Provider` 는 존재하지 않는 클래스(실제 `KakaoProvider`).
    9/12 tinker 테스트는 redirect 경로를 실행하지 않아 둘 다 못 잡음.
  - 주소창 원문 = 코어 `SafeExpressionEvaluator` 가 `encodeURIComponent` 를 호출하지 못해 예외 →
    `DataBindingEngine.resolveBindings` 가 catch 후 `{{ }}` 원문 반환(침묵 실패).
  - ② = 코어 `TemplateApp::executeInitActions` 가 init action 을 ActionDefinition 으로 옮길 때
    `actions` 필드를 복사하지 않음 → `sequence` 가 빈 배열로 조용히 종료 → `POST /exchange` 미호출
    (접근 로그로 확인). 9/13 의 `/api` 접두사 수정 때도 트리거된 적 없던 잠재 버그.
  - ③ = 코어 `AuthManager.isAuthenticated` 는 `login` 핸들러의 private `establishSession` 또는 부팅
    `preloadAuth` 로만 true. SPA `navigate` 후엔 false 로 남아 `DataSourceManager` 가
    `auth_required` 데이터소스(`current_user`)를 건너뛰고 fallback 으로 `_global.currentUser` 를 덮어씀.
  - 부가: `redirect` 쿼리 무검증 → 세션 → 콜백 URL → 프론트 `navigate` 로 흘러가는 오픈 리다이렉트 표면.
- **코어 표현식 파서 실제 지원 범위** (`SafeExpressionEvaluator.ts` 구현 + 원본 파일을 node 로 직접
  실행 + 배포 번들 `template-engine.min.js` 대조로 확정):
  - `??` / `?.` / 삼항 / `.includes()` / 화살표함수(구조분해 파라미터) / `typeof` / `Object.entries` 지원.
  - 호출 가능한 전역은 `WHITELIST_GLOBALS` 뿐: `Math JSON Date Array Object(일부) Number String Boolean
    Set Map WeakSet WeakMap parseInt parseFloat isNaN isFinite`. **`encodeURIComponent`/`encodeURI`/
    `decodeURIComponent` 호출 불가**(`DataBindingEngine.extractVariablesFromExpression` 예약어 목록엔
    이름이 있어 헷갈리지만 평가기엔 없음 → `Attempted to call a non-function value`).
  - `query` 는 레이아웃 렌더·initActions 컨텍스트 모두에 바인딩됨(`TemplateApp` initialDataContext).
  - 평가 실패 시 에러 토스트 없이 `{{ }}` 원문을 그대로 출력.
  - 플러그인이 만드는 나머지 표현식 30개(마이페이지 위젯·관리자 설정 JSON 포함) 전수 평가 → 실패는
    `encodeURIComponent` 2건뿐.
  - initActions 에서 `sequence`+`actions` 는 동작하지 않음 — 핸들러를 직접 둘 것(코어 레이아웃도 동일 패턴).
- **수정** (코어 무변경, 커밋 `24431ec` `55f118f` `cd19d39`):
  - `SocialiteManager` 를 `new SocialiteManager(app())` 로 직접 생성(파사드 제거), `KakaoProvider`
    클래스명 수정. provider 조립의 `Throwable` 도 500 대신 `/login?social_error=provider_unavailable`.
  - 로그인 카카오/구글 버튼 href 를 파라미터 없는 고정 경로로.
  - `Support/RedirectPath::sanitize` 신설: 단일 `/` 로 시작하는 내부 상대경로만 허용. `//host`, `/\host`,
    스킴/호스트 포함 URL, 역슬래시·제어문자·공백, `{{`/`}}`, `/login`, `/api/*` 는 전부 `/` 로 조용히
    폴백. 진입(redirect)·콜백·교환 응답 3단계 모두 적용. 돌아갈 경로는 URL 대신 교환코드 캐시에
    담아 exchange 응답 `redirect_path` 로만 전달(프론트가 쿼리스트링을 읽지 않음).
  - 교환 init action 을 `apiCall` 직접 호출로 변경.
  - 교환 성공 후 `saveToLocalStorage` → `openWindow(_self)`(전체 이동)로 부팅 `preloadAuth` 가 인증
    상태를 복원하게 함. 전체 이동이라 로그인 성공 토스트는 제거.
- **검증**:
  - 서빙 레이아웃(`/api/layouts/wc-community/auth/login.json`)에서 href 가 고정 경로로 렌더됨 확인
    (반영에 `template:cache-clear` 필요, 브라우저는 레이아웃 JSON `max-age=3600` 이라 기존 창은 구버전 사용).
  - redirect 파라미터 없음 / `//example.com` / `https://example.com` / `{{foo}}` / 표현식 원문 5케이스
    전부 500 없이 `kauth.kakao.com` 302. sanitize 단위 15케이스(`/board/free?page=2#c` 통과 등) 기대대로.
  - 공개 도메인 기준 `redirect_uri=https://blog.william-cho.com/api/plugins/g7-social_login/kakao/callback`
    — 카카오 실왕복 성공(불일치면 카카오가 거부)으로 콘솔 등록값과 일치 확인.
  - 가짜 콜백(code/state 위조) → `/login?social_error=oauth_failed` 302, 가짜 교환코드 → 422.
  - 윌리엄 실브라우저(시크릿 창) 카카오 로그인 E2E: redirect 302 → 콜백 302 → `POST /exchange` 200 →
    `GET /api/auth/user` 200, 홈에서 새로고침 없이 로그인 상태 확인.
  - 구글: client_id 미설정 상태라 `/login?social_error=provider_unavailable` 폴백만 확인(실로그인 미검증).
  - 테스트 데이터 정리: E2E 로 생성된 user 2 와 연동 1·플래그 1·토큰 6·활동로그 2 삭제.
- **남은 것 / 별건**: atozai 설치본에 동일 결함 3종 미반영. 플러그인 vendor 의 `illuminate/*` v12.69.2 가
  prepend 오토로더로 코어 v12.69.1 클래스를 사이트 전체에서 가리는 문제 확인(별도 작업으로 분리).
  구글 실로그인 E2E.
