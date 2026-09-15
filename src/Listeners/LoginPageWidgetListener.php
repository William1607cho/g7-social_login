<?php

namespace Plugins\G7\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Plugins\G7\SocialLogin\Support\BrandIcons;

/**
 * 로그인 화면(`auth/login`)에 카카오/구글 버튼 + 교환코드 처리 init_action 을
 * `core.layout_extension.after_apply` 필터로 주입한다. 로그인 화면에는
 * extension_point 가 없어(조사 결과) 코어/템플릿 파일을 건드리지 않는 이 방식이
 * 유일한 무변경 주입 경로다 (g7-forum-addon 의 board/show 위젯 주입과 동일 패턴).
 *
 * 버튼은 `_global.plugins['g7-social_login'].{provider}_enabled` 로 켜져 있을
 * 때만 보인다(플러그인 설정의 frontend_schema 노출값, 별도 API 호출 불필요).
 */
class LoginPageWidgetListener implements HookListenerInterface
{
    private const WIDGET_ID = 'g7_social_login_widget';

    private const INIT_ACTION_MARKER = 'g7_social_login_exchange';

    /** "로그인 폼" 카드 Div 를 찾기 위한 구조적 시그니처(sirsoft-basic 코어, 원본 그대로) */
    private const FORM_CARD_CLASSNAME = 'w-full max-w-md p-8 bg-white dark:bg-gray-800 rounded-lg shadow';

    /** 그 안에서 "하단 링크 영역" Div 바로 앞에 버튼을 끼워 넣는다(코어 구조 앵커) */
    private const BOTTOM_LINKS_CLASSNAME = 'mt-4 flex items-center justify-between text-sm';

    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'injectWidget',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    public function handle(...$args): void {}

    public function injectWidget(array $layout, int $templateId = 0): array
    {
        if (($layout['layout_name'] ?? null) !== 'auth/login') {
            return $layout;
        }

        if (! isset($layout['components']) || ! is_array($layout['components'])) {
            return $layout;
        }

        if (! $this->treeHasNodeId($layout['components'], self::WIDGET_ID)) {
            $injected = 0;
            $layout['components'] = $this->spliceIntoFormCard($layout['components'], $injected);

            if ($injected === 0) {
                Log::error('[g7-social_login] 로그인 폼 카드 앵커를 찾지 못해 소셜 로그인 버튼을 주입하지 못했습니다. sirsoft-basic auth/login 레이아웃 구조 변경 여부 확인 필요.', [
                    'template_id' => $templateId,
                ]);
            }
        }

        $layout['initActions'] = $this->ensureExchangeInitAction($layout['initActions'] ?? []);

        return $layout;
    }

    /**
     * @param  array<int, array>  $nodes
     */
    private function spliceIntoFormCard(array $nodes, int &$injected): array
    {
        foreach ($nodes as &$node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['props']['className'] ?? null) === self::FORM_CARD_CLASSNAME && isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->insertBeforeBottomLinks($node['children'], $injected);

                if ($injected > 0) {
                    continue;
                }
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->spliceIntoFormCard($node['children'], $injected);
            }
        }

        return $nodes;
    }

    private function insertBeforeBottomLinks(array $children, int &$injected): array
    {
        $anchorIndex = null;

        foreach ($children as $index => $child) {
            if (is_array($child) && ($child['props']['className'] ?? null) === self::BOTTOM_LINKS_CLASSNAME) {
                $anchorIndex = $index;
                break;
            }
        }

        if ($anchorIndex === null) {
            return $children;
        }

        array_splice($children, $anchorIndex, 0, [$this->buildWidgetNode()]);
        $injected++;

        return $children;
    }

    private function buildWidgetNode(): array
    {
        return [
            'id' => self::WIDGET_ID,
            'comment' => 'g7-social_login: 소셜 로그인 버튼',
            'type' => 'basic',
            'name' => 'Div',
            'if' => "{{_global.plugins?.['g7-social_login']?.kakao_enabled || _global.plugins?.['g7-social_login']?.google_enabled}}",
            'props' => ['className' => 'mt-6 space-y-3'],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => ['className' => 'relative my-4'],
                    'children' => [
                        [
                            'type' => 'basic', 'name' => 'Div',
                            'props' => ['className' => 'absolute inset-0 flex items-center'],
                            'children' => [[
                                'type' => 'basic', 'name' => 'Div',
                                'props' => ['className' => 'w-full border-t border-gray-200 dark:border-gray-700'],
                            ]],
                        ],
                        [
                            'type' => 'basic', 'name' => 'Div',
                            'props' => ['className' => 'relative flex justify-center'],
                            'children' => [[
                                'type' => 'basic', 'name' => 'Span',
                                'props' => ['className' => 'px-3 bg-white dark:bg-gray-800 text-sm text-gray-500 dark:text-gray-400'],
                                'text' => '$t:g7-social_login.login.divider',
                            ]],
                        ],
                    ],
                ],
                [
                    'type' => 'basic',
                    'name' => 'A',
                    'if' => "{{_global.plugins?.['g7-social_login']?.kakao_enabled}}",
                    'props' => [
                        // 고정 경로 — g7 표현식 평가기(SafeExpressionEvaluator)는 encodeURIComponent 등
                        // 화이트리스트 밖 전역 함수를 호출하지 못하고, 실패 시 {{ }} 원문을 그대로 흘린다.
                        'href' => '/api/plugins/g7-social_login/kakao/redirect',
                        'className' => 'w-full flex items-center justify-center gap-2 py-3 rounded-lg font-medium bg-[#FEE500] text-black/85 hover:opacity-90 transition-opacity',
                    ],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Img',
                            'props' => [
                                'src' => BrandIcons::kakaoDataUri(),
                                'alt' => '',
                                'className' => 'w-5 h-5',
                            ],
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'text' => '$t:g7-social_login.login.kakao_button',
                        ],
                    ],
                ],
                [
                    'type' => 'basic',
                    'name' => 'A',
                    'if' => "{{_global.plugins?.['g7-social_login']?.google_enabled}}",
                    'props' => [
                        'href' => '/api/plugins/g7-social_login/google/redirect',
                        'className' => 'w-full flex items-center justify-center gap-2 py-3 rounded-lg font-medium border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors',
                    ],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Img',
                            'props' => [
                                'src' => BrandIcons::googleDataUri(),
                                'alt' => '',
                                'className' => 'w-5 h-5',
                            ],
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'text' => '$t:g7-social_login.login.google_button',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function ensureExchangeInitAction(array $initActions): array
    {
        foreach ($initActions as $action) {
            if (($action['_marker'] ?? null) === self::INIT_ACTION_MARKER) {
                return $initActions;
            }
        }

        // sequence 로 감싸지 않고 apiCall 을 직접 둔다 — 코어 TemplateApp::executeInitActions 는
        // init action 을 ActionDefinition 으로 옮길 때 `actions` 필드를 복사하지 않아, sequence 가
        // 빈 배열로 조용히 건너뛰어져 교환 API 가 한 번도 호출되지 않았다(실측).
        $initActions[] = [
            '_marker' => self::INIT_ACTION_MARKER,
            'if' => '{{query?.social_exchange}}',
            'handler' => 'apiCall',
            'target' => '/api/plugins/g7-social_login/exchange',
            'params' => [
                'method' => 'POST',
                'body' => ['code' => '{{query.social_exchange}}'],
            ],
            'onSuccess' => [
                [
                    'handler' => 'saveToLocalStorage',
                    'params' => ['key' => 'auth_token', 'value' => '{{response.token}}'],
                ],
                [
                    // SPA navigate 가 아니라 전체 이동(window.location.assign)이어야 한다.
                    // 코어 AuthManager 의 인증 상태(isAuthenticated)는 `login` 핸들러 내부의 private
                    // establishSession 또는 부팅 시 preloadAuth 로만 켜진다. navigate 로 이동하면
                    // 상태가 false 로 남아 DataSourceManager 가 auth_required 데이터소스(current_user)를
                    // 건너뛰고 fallback 으로 _global.currentUser 를 덮어써, 새로고침 전까지 비로그인처럼
                    // 보였다(실측). 전체 이동은 부팅 preloadAuth 를 거쳐 저장된 토큰으로 상태를 복원한다.
                    'handler' => 'openWindow',
                    // 서버가 검증한 값만 쓴다(쿼리스트링의 redirect 는 읽지 않음).
                    'target' => '{{response.redirect_path ?? \'/\'}}',
                    'params' => ['target' => '_self'],
                ],
            ],
            'onError' => [
                [
                    'handler' => 'toast',
                    'params' => ['type' => 'error', 'message' => '{{error.message}}'],
                ],
            ],
        ];

        $initActions[] = [
            'if' => '{{query?.social_error}}',
            'handler' => 'toast',
            'params' => [
                'type' => 'error',
                'message' => '{{query.social_error}}',
            ],
        ];

        return $initActions;
    }

    /**
     * @param  array<int, array>  $nodes
     */
    private function treeHasNodeId(array $nodes, string $id): bool
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['id'] ?? null) === $id) {
                return true;
            }

            if (isset($node['children']) && is_array($node['children']) && $this->treeHasNodeId($node['children'], $id)) {
                return true;
            }
        }

        return false;
    }
}
