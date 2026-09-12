<?php

namespace Plugins\G7\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\G7\SocialLogin\Models\SocialLoginUserFlag;

/**
 * 소셜 전용 가입자가 직접 비밀번호를 설정(변경/재설정)하면 이제 실비밀번호로
 * 로그인 가능하므로 `g7_social_login_user_flags` 행을 지운다 — 행 부재는
 * "실비밀번호 보유"를 뜻하므로(SocialAuthService::canUnlink), 이후 연동 해제
 * 차단 로직이 더 이상 이 사용자를 걸지 않는다.
 */
class PasswordChangedFlagListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.after_password_changed' => ['method' => 'handle', 'type' => 'action'],
        ];
    }

    public function handle(...$args): void
    {
        $user = $args[0] ?? null;

        if ($user instanceof User) {
            SocialLoginUserFlag::where('user_id', $user->id)->delete();
        }
    }
}
