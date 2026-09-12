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
                        'href' => "/api/plugins/g7-social_login/kakao/redirect?redirect={{encodeURIComponent(query.redirect ?? '/')}}",
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
                        'href' => "/api/plugins/g7-social_login/google/redirect?redirect={{encodeURIComponent(query.redirect ?? '/')}}",
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

        $initActions[] = [
            '_marker' => self::INIT_ACTION_MARKER,
            'if' => '{{query?.social_exchange}}',
            'handler' => 'sequence',
            'actions' => [
                [
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
                            'handler' => 'setState',
                            'params' => ['target' => 'global', 'currentUser' => '{{response.data}}'],
                        ],
                        [
                            'handler' => 'toast',
                            'params' => ['type' => 'success', 'message' => '$t:auth.login_success'],
                        ],
                        [
                            'handler' => 'navigate',
                            'params' => ['path' => '{{query.redirect ?? \'/\'}}'],
                        ],
                    ],
                    'onError' => [
                        [
                            'handler' => 'toast',
                            'params' => ['type' => 'error', 'message' => '{{error.message}}'],
                        ],
                    ],
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
