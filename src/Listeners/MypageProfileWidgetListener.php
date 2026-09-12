<?php

namespace Plugins\G7\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Plugins\G7\SocialLogin\Support\BrandIcons;

/**
 * 마이페이지 프로필 화면(`mypage/profile`)에 "연동된 소셜 계정" 카드를
 * `core.layout_extension.after_apply` 로 주입한다. 앵커는 프로필 뷰의
 * "서명 및 자기소개 카드"(id: profile_view_bio_card, sirsoft-basic 코어 원본) —
 * 그 바로 앞에 splice 한다.
 */
class MypageProfileWidgetListener implements HookListenerInterface
{
    private const WIDGET_ID = 'g7_social_login_profile_widget';

    private const ANCHOR_ID = 'profile_view_bio_card';

    private const DATA_SOURCE_ID = 'g7_social_login_accounts';

    private const TOAST_MARKER = 'g7_social_login_link_toast';

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
        if (($layout['layout_name'] ?? null) !== 'mypage/profile') {
            return $layout;
        }

        if (! isset($layout['components']) || ! is_array($layout['components'])) {
            return $layout;
        }

        if (! $this->treeHasNodeId($layout['components'], self::WIDGET_ID)) {
            $injected = 0;
            $layout['components'] = $this->spliceBeforeAnchor($layout['components'], $injected);

            if ($injected === 0) {
                Log::error('[g7-social_login] 프로필 화면 앵커(profile_view_bio_card)를 찾지 못해 소셜 연동 위젯을 주입하지 못했습니다. sirsoft-basic mypage/profile 레이아웃 구조 변경 여부 확인 필요.', [
                    'template_id' => $templateId,
                ]);
            }
        }

        $layout['data_sources'] = $this->ensureDataSource($layout['data_sources'] ?? []);
        $layout['initActions'] = $this->ensureLinkToast($layout['initActions'] ?? []);

        return $layout;
    }

    private function spliceBeforeAnchor(array $nodes, int &$injected): array
    {
        $anchorIndex = null;

        foreach ($nodes as $index => $node) {
            if (is_array($node) && ($node['id'] ?? null) === self::ANCHOR_ID) {
                $anchorIndex = $index;
                break;
            }
        }

        if ($anchorIndex !== null) {
            array_splice($nodes, $anchorIndex, 0, [$this->buildWidgetNode()]);
            $injected++;

            return $nodes;
        }

        foreach ($nodes as &$node) {
            if (is_array($node) && isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->spliceBeforeAnchor($node['children'], $injected);

                if ($injected > 0) {
                    break;
                }
            }
        }

        return $nodes;
    }

    private function buildWidgetNode(): array
    {
        return [
            'id' => self::WIDGET_ID,
            'comment' => 'g7-social_login: 연동된 소셜 계정',
            'type' => 'basic',
            'name' => 'Div',
            'props' => ['className' => 'bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6'],
            'responsive' => ['portable' => ['props' => ['className' => 'bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4']]],
            'children' => [
                [
                    'type' => 'basic', 'name' => 'H3',
                    'props' => ['className' => 'text-base font-semibold text-gray-900 dark:text-white mb-4 pb-3 border-b border-gray-200 dark:border-gray-700'],
                    'text' => '$t:g7-social_login.profile.section_title',
                ],
                [
                    'type' => 'basic', 'name' => 'Div',
                    'props' => ['className' => 'space-y-3'],
                    'children' => [
                        $this->buildProviderRow('kakao'),
                        $this->buildProviderRow('google'),
                    ],
                ],
            ],
        ];
    }

    private function buildProviderRow(string $provider): array
    {
        // 순수 불리언 표현식(중괄호 없음) — 사용처마다 필요한 형태로 직접 {{ }}로 감싼다.
        // 미리 감싸두면 삼항식 등에 재사용할 때 {{ }} 가 중첩/분리되어 깨진 바인딩이 된다
        // (실측: className/text/if 세 곳에서 이 문제로 상태 텍스트와 "연동하기" 버튼이
        // 전혀 렌더되지 않는 침묵 실패가 있었음).
        $isLinked = '('.self::DATA_SOURCE_ID.".data?.linked_providers ?? []).includes('{$provider}')";
        $isLinkedExpr = "{{{$isLinked}}}";
        $enabledExpr = "{{_global.plugins?.['g7-social_login']?.{$provider}_enabled}}";

        return [
            'type' => 'basic', 'name' => 'Div',
            'if' => $enabledExpr,
            'props' => ['className' => 'flex items-center justify-between p-3 border border-gray-200 dark:border-gray-700 rounded-lg'],
            'children' => [
                [
                    'type' => 'basic', 'name' => 'Div',
                    'props' => ['className' => 'flex items-center gap-3'],
                    'children' => [
                        [
                            'type' => 'basic', 'name' => 'Img',
                            'props' => [
                                'src' => $provider === 'kakao' ? BrandIcons::kakaoDataUri() : BrandIcons::googleDataUri(),
                                'alt' => '',
                                'className' => 'w-6 h-6 flex-shrink-0',
                            ],
                        ],
                        [
                            'type' => 'basic', 'name' => 'Div',
                            'children' => [
                                [
                                    'type' => 'basic', 'name' => 'Span',
                                    'props' => ['className' => 'block text-sm font-medium text-gray-900 dark:text-white'],
                                    'text' => "\$t:g7-social_login.profile.{$provider}",
                                ],
                                [
                                    'type' => 'basic', 'name' => 'Span',
                                    'props' => ['className' => "{{{$isLinked} ? 'block text-xs text-green-600 dark:text-green-400' : 'block text-xs text-gray-400 dark:text-gray-500'}}"],
                                    'text' => "{{{$isLinked} ? \$t('g7-social_login.profile.linked') : \$t('g7-social_login.profile.not_linked')}}",
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'basic', 'name' => 'Button',
                    'if' => "{{!({$isLinked})}}",
                    'props' => [
                        'type' => 'button',
                        'className' => 'inline-flex items-center gap-2 px-3 py-1.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors',
                    ],
                    'text' => "{{'(' + \$t('g7-social_login.profile.{$provider}') + ') ' + \$t('g7-social_login.profile.link_button')}}",
                    'actions' => [[
                        'type' => 'click',
                        'handler' => 'apiCall',
                        'auth_required' => true,
                        'target' => "/api/plugins/g7-social_login/{$provider}/link/prepare",
                        'params' => ['method' => 'POST'],
                        'onSuccess' => [[
                            'handler' => 'openWindow',
                            'target' => '{{response.data.redirect_url}}',
                            'params' => ['target' => '_self'],
                        ]],
                        'onError' => [[
                            'handler' => 'toast',
                            'params' => ['type' => 'error', 'message' => '{{error.message}}'],
                        ]],
                    ]],
                ],
                [
                    'type' => 'basic', 'name' => 'Button',
                    'if' => $isLinkedExpr,
                    'props' => [
                        'type' => 'button',
                        'className' => 'inline-flex items-center gap-2 px-3 py-1.5 border border-red-300 dark:border-red-800 text-red-600 dark:text-red-400 rounded-lg text-sm font-medium hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors',
                    ],
                    'text' => "{{'(' + \$t('g7-social_login.profile.{$provider}') + ') ' + \$t('g7-social_login.profile.unlink_button')}}",
                    'actions' => [[
                        'type' => 'click',
                        'handler' => 'apiCall',
                        'auth_required' => true,
                        'target' => "/api/plugins/g7-social_login/{$provider}/unlink",
                        'params' => ['method' => 'DELETE'],
                        'confirm' => '$t:g7-social_login.profile.unlink_confirm',
                        'onSuccess' => [
                            ['handler' => 'toast', 'params' => ['type' => 'success', 'message' => '$t:g7-social_login.profile.unlink_success']],
                            ['handler' => 'refetchDataSource', 'params' => ['dataSourceId' => self::DATA_SOURCE_ID]],
                        ],
                        'onError' => [[
                            'handler' => 'toast',
                            'params' => ['type' => 'error', 'message' => '{{error.message}}'],
                        ]],
                    ]],
                ],
            ],
        ];
    }

    private function ensureDataSource(array $dataSources): array
    {
        foreach ($dataSources as $ds) {
            if (($ds['id'] ?? null) === self::DATA_SOURCE_ID) {
                return $dataSources;
            }
        }

        $dataSources[] = [
            'id' => self::DATA_SOURCE_ID,
            'type' => 'api',
            'endpoint' => '/api/plugins/g7-social_login/accounts',
            'method' => 'GET',
            'auto_fetch' => true,
            'auth_required' => true,
            'loading_strategy' => 'progressive',
            'fallback' => ['data' => ['linked_providers' => []]],
        ];

        return $dataSources;
    }

    private function ensureLinkToast(array $initActions): array
    {
        foreach ($initActions as $action) {
            if (($action['_marker'] ?? null) === self::TOAST_MARKER) {
                return $initActions;
            }
        }

        $initActions[] = [
            '_marker' => self::TOAST_MARKER,
            'if' => "{{query?.social_link === 'success'}}",
            'handler' => 'toast',
            'params' => ['type' => 'success', 'message' => '$t:g7-social_login.profile.link_success'],
        ];

        $initActions[] = [
            'if' => "{{query?.social_link === 'error'}}",
            'handler' => 'toast',
            'params' => ['type' => 'error', 'message' => '{{query.social_error ?? \'\'}}'],
        ];

        return $initActions;
    }

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
