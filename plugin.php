<?php

namespace Plugins\G7\SocialLogin;

use App\Extension\AbstractPlugin;
use Plugins\G7\SocialLogin\Listeners\LoginPageWidgetListener;
use Plugins\G7\SocialLogin\Listeners\MypageProfileWidgetListener;
use Plugins\G7\SocialLogin\Listeners\NotificationExtractDataListener;
use Plugins\G7\SocialLogin\Listeners\PasswordChangedFlagListener;
use Plugins\G7\SocialLogin\Listeners\ProfileEditPasswordGateListener;

/**
 * 소셜 로그인(카카오/구글) 플러그인.
 *
 * sirsoft-basic 템플릿과 코어 인증 컨트롤러는 파일 한 줄도 수정하지 않는다 —
 * 로그인/마이페이지 화면 주입은 `core.layout_extension.after_apply` 필터 훅으로,
 * 인증은 `Socialite::buildProvider()` 로 코어 `config/services.php` 를 건드리지
 * 않고 플러그인 자체 설정(DB)만으로 처리한다.
 */
class Plugin extends AbstractPlugin
{
    public function getMetadata(): array
    {
        return [
            'author' => 'William Cho',
            'license' => 'MIT',
            'keywords' => ['auth', 'oauth', 'kakao', 'google', 'social-login'],
        ];
    }

    public function getHookListeners(): array
    {
        return [
            LoginPageWidgetListener::class,
            MypageProfileWidgetListener::class,
            NotificationExtractDataListener::class,
            PasswordChangedFlagListener::class,
            ProfileEditPasswordGateListener::class,
        ];
    }

    /**
     * 설정 저장 검증(UpdatePluginSettingsRequest)과 민감값 마스킹
     * (PluginSettingsController::maskSensitive)이 이 스키마를 직접 참조한다 —
     * config/settings/defaults.json의 frontend_schema만으로는 저장 경로가
     * 채워지지 않는다(빈 스키마 → 검증 규칙 0개 → validated()가 모든 필드를
     * 걸러내 저장 무동작, 값은 그대로인데 "성공" 응답만 오는 침묵 실패).
     */
    public function getSettingsSchema(): array
    {
        return [
            'kakao_enabled' => ['type' => 'boolean'],
            'kakao_client_id' => ['type' => 'string'],
            'kakao_client_secret' => ['type' => 'string', 'sensitive' => true],
            'google_enabled' => ['type' => 'boolean'],
            'google_client_id' => ['type' => 'string'],
            'google_client_secret' => ['type' => 'string', 'sensitive' => true],
        ];
    }

    public function getConfigValues(): array
    {
        return [
            'kakao_enabled' => false,
            'kakao_client_id' => '',
            'kakao_client_secret' => '',
            'google_enabled' => false,
            'google_client_id' => '',
            'google_client_secret' => '',
        ];
    }

    public function getNotificationDefinitions(): array
    {
        return [
            [
                'type' => 'social_account_auto_linked',
                'hook_prefix' => 'g7-social_login',
                'name' => ['ko' => '소셜 계정 자동 연동', 'en' => 'Social account auto-linked'],
                'description' => [
                    'ko' => '다른 방법으로 가입된 계정에 소셜 로그인이 자동 연동되었을 때 발송',
                    'en' => 'Sent when a social login is auto-linked to an existing account',
                ],
                'channels' => ['mail', 'database'],
                'hooks' => ['g7-social_login.account.auto_linked'],
                'variables' => [
                    ['key' => 'name', 'description' => '수신자 이름'],
                    ['key' => 'app_name', 'description' => '사이트 이름'],
                    ['key' => 'provider_name', 'description' => '연동된 소셜 제공자명'],
                    ['key' => 'provider_email', 'description' => '소셜 계정 이메일'],
                ],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'recipients' => [['type' => 'trigger_user']],
                        'subject' => [
                            'ko' => '[{app_name}] {provider_name} 계정이 회원님의 계정에 자동으로 연동되었습니다',
                            'en' => '[{app_name}] Your {provider_name} account was automatically linked',
                        ],
                        'body' => [
                            'ko' => '<p>{name}님, 안녕하세요.</p><p>{provider_name} 계정({provider_email})이 회원님의 {app_name} 계정에 자동으로 연동되었습니다. 본인이 아니라면 즉시 비밀번호를 변경하고 마이페이지에서 연동을 해제해주세요.</p>',
                            'en' => "<p>Hi {name},</p><p>A {provider_name} account ({provider_email}) was automatically linked to your {app_name} account. If this wasn't you, change your password immediately and unlink it from your profile page.</p>",
                        ],
                    ],
                    [
                        'channel' => 'database',
                        'recipients' => [['type' => 'trigger_user']],
                        'subject' => [
                            'ko' => '{provider_name} 계정이 자동으로 연동되었습니다',
                            'en' => 'Your {provider_name} account was automatically linked',
                        ],
                        'body' => [
                            'ko' => '{provider_name} 계정({provider_email})이 회원님의 계정에 자동으로 연동되었습니다. 본인이 아니라면 마이페이지에서 연동을 해제해주세요.',
                            'en' => "A {provider_name} account ({provider_email}) was automatically linked to your account. If this wasn't you, unlink it from your profile page.",
                        ],
                    ],
                ],
            ],
        ];
    }
}
