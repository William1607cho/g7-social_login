# g7-social_login

## 목적
atozai.william-cho.com(그누보드7)에 카카오·구글 소셜 로그인을 커스텀 플러그인으로 추가.
기존 이메일 가입 회원과 이메일 일치 시 자동 연동(단, 이메일 인증된 계정만), 마이페이지에서
수동 연동/해제 지원.

## 시작일
2026-09-12

## 상태
진행중 — 코드 구현·설치·기능 테스트 완료. 카카오/구글 개발자 콘솔 앱 등록(윌리엄) 및
실제 로그인 E2E 테스트 대기.

## 레포명
로컬 전용 (`Dev-Project/20260912-g7-social_login`), GitHub 공개 배포는 미정 (윌리엄 결정 대기)

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
