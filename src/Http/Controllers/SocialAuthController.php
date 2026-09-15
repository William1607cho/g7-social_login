<?php

namespace Plugins\G7\SocialLogin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugins\G7\SocialLogin\Models\SocialAccount;
use Plugins\G7\SocialLogin\Services\SocialAuthService;
use Plugins\G7\SocialLogin\Support\RedirectPath;

class SocialAuthController extends Controller
{
    private const SESSION_REDIRECT = 'g7sl.redirect';

    private const SESSION_LINK_NONCE = 'g7sl.link_nonce';

    public function __construct(private readonly SocialAuthService $service) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        try {
            $driver = $this->service->driverFor($provider);
        } catch (\RuntimeException $e) {
            return $this->frontendLoginError('provider_unavailable');
        } catch (\Throwable $e) {
            // provider 조립 자체의 실패(클래스/바인딩 누락 등)도 500 대신 로그인 화면 오류로 돌려보낸다.
            Log::error('g7-social_login: OAuth provider 조립 실패', ['provider' => $provider, 'exception' => get_class($e), 'error' => $e->getMessage()]);

            return $this->frontendLoginError('provider_unavailable');
        }

        $request->session()->put(self::SESSION_REDIRECT, RedirectPath::sanitize($request->query('redirect')));

        $linkNonce = $request->query('link_nonce');
        if (is_string($linkNonce) && $linkNonce !== '') {
            $request->session()->put(self::SESSION_LINK_NONCE, $linkNonce);
        } else {
            $request->session()->forget(self::SESSION_LINK_NONCE);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        // 진입 시 이미 검증했지만 세션 값을 그대로 믿지 않고 콜백에서도 다시 검증한다.
        $redirectAfter = RedirectPath::sanitize($request->session()->pull(self::SESSION_REDIRECT));
        $linkNonce = $request->session()->pull(self::SESSION_LINK_NONCE);

        try {
            $driver = $this->service->driverFor($provider);
            $socialUser = $driver->user();
        } catch (\Throwable $e) {
            Log::warning('g7-social_login: OAuth 콜백 실패', ['provider' => $provider, 'error' => $e->getMessage()]);

            return $linkNonce
                ? $this->frontendProfileError('oauth_failed')
                : $this->frontendLoginError('oauth_failed');
        }

        if (is_string($linkNonce) && $linkNonce !== '') {
            return $this->handleLinkCallback($provider, $linkNonce, (string) $socialUser->getId(), $socialUser->getEmail());
        }

        try {
            $result = $this->service->handleLogin($provider, $socialUser);
        } catch (\RuntimeException $e) {
            $key = $e->getMessage() === 'email_exists_unverified' ? 'email_exists_unverified' : 'login_failed';

            return $this->frontendLoginError($key);
        }

        // 돌아갈 경로는 URL 에 싣지 않고 교환코드 캐시에 담는다 — 프론트가 쿼리스트링을
        // 읽어 이동 경로를 정하면 검증을 우회한 오픈 리다이렉트 표면이 생긴다.
        $code = $this->service->issueExchangeCode($result['user'], $redirectAfter);

        return redirect('/login?'.http_build_query([
            'social_exchange' => $code,
        ]));
    }

    private function handleLinkCallback(string $provider, string $nonce, string $providerUserId, ?string $email): RedirectResponse
    {
        $userId = $this->service->consumeLinkNonce($nonce, $provider);

        if ($userId === null) {
            return $this->frontendProfileError('link_expired');
        }

        if ($this->service->isProviderIdTakenByAnotherUser($provider, $providerUserId, $userId)) {
            return $this->frontendProfileError('link_taken');
        }

        $user = User::find($userId);

        if (! $user) {
            return $this->frontendProfileError('link_expired');
        }

        $this->service->linkAccount($user, $provider, $providerUserId, $email);

        return redirect('/mypage/profile?social_link=success');
    }

    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string']]);

        $result = $this->service->consumeExchangeCode($validated['code']);

        if (! $result) {
            return response()->json(['message' => __('auth.login_failed')], 422);
        }

        $user = $result['user'];
        $user->load(['roles.permissions']);

        return response()->json([
            'message' => __('auth.login_success'),
            'data' => (new UserResource($user))->toAuthArray($request),
            'token' => $result['token'],
            'redirect_path' => RedirectPath::sanitize($result['redirect_path']),
        ]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $linked = SocialAccount::where('user_id', $request->user()->id)
            ->pluck('provider')
            ->all();

        return response()->json([
            'data' => [
                'linked_providers' => $linked,
            ],
        ]);
    }

    public function linkPrepare(Request $request, string $provider): JsonResponse
    {
        if (! $this->service->isEnabled($provider)) {
            return response()->json(['message' => __('common.not_found')], 404);
        }

        $nonce = $this->service->createLinkNonce($request->user(), $provider);

        return response()->json([
            'data' => [
                'redirect_url' => '/api/plugins/'.SocialAuthService::IDENTIFIER."/{$provider}/redirect?".http_build_query([
                    'link_nonce' => $nonce,
                ]),
            ],
        ]);
    }

    public function unlink(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();

        if (! $this->service->canUnlink($user, $provider)) {
            return response()->json(['message' => __('g7-social_login::messages.profile.unlink_blocked_no_password')], 422);
        }

        SocialAccount::where('user_id', $user->id)
            ->where('provider', $provider)
            ->delete();

        return response()->json(['message' => __('g7-social_login::messages.profile.unlink_success')]);
    }

    private function frontendLoginError(string $key): RedirectResponse
    {
        return redirect('/login?'.http_build_query(['social_error' => $key]));
    }

    private function frontendProfileError(string $key): RedirectResponse
    {
        return redirect('/mypage/profile?'.http_build_query(['social_link' => 'error', 'social_error' => $key]));
    }
}
