<?php

namespace Plugins\G7\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\G7\SocialLogin\Models\SocialAccount;

/**
 * `g7-social_login.notification.extract_data` 필터 — `auto_linked` 알림 정의가
 * 구독하는 훅(`g7-social_login.account.auto_linked`)이 발화될 때, payload
 * (SocialAccount 모델)를 알림 변수 + 수신자 컨텍스트로 변환한다.
 */
class NotificationExtractDataListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'g7-social_login.notification.extract_data' => ['method' => 'extractData', 'type' => 'filter'],
        ];
    }

    /** interface 충족용 — 실제 필터 로직은 extractData()가 담당 */
    public function handle(...$args): void {}

    /**
     * @param  array  $default  기본 반환값
     * @param  string  $type  notification_definitions.type (여기서는 'social_account_auto_linked' 뿐)
     * @param  array  $args  doAction 에 전달된 원본 인수 — [$account]
     */
    public function extractData(array $default, string $type, array $args): array
    {
        if ($type !== 'social_account_auto_linked') {
            return $default;
        }

        $account = $args[0] ?? null;

        if (! $account instanceof SocialAccount) {
            return $default;
        }

        $account->loadMissing('user');
        $user = $account->user;

        if (! $user) {
            return $default;
        }

        $providerNames = ['kakao' => '카카오', 'google' => 'Google'];

        return [
            'data' => [
                'name' => $user->name,
                'app_name' => config('app.name'),
                'provider_name' => $providerNames[$account->provider] ?? $account->provider,
                'provider_email' => $account->provider_email ?? '',
            ],
            'context' => [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
            ],
        ];
    }
}
