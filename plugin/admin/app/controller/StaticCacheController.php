<?php

namespace plugin\admin\app\controller;

use app\model\Post;

use function publish_static;

use support\Request;
use support\Response;
use Throwable;

/**
 * 静态缓存设置
 * - 管理 StaticGenerator 自调用所需基址配置
 * - 键：static_base_url / site_scheme / site_host / site_port
 */
class StaticCacheController extends Base
{
    /**
     * 展开URL中的区间语法：
     * - {a..b} 或 {a..b..step}
     * - 支持同一URL中多个区间的笛卡尔积展开
     */
    protected function expandUrlPatterns(string $pattern): array
    {
        // 快速判断，无区间直接返回
        if (strpos($pattern, '{') === false || strpos($pattern, '..') === false) {
            return [$pattern];
        }

        // 解析所有 {..} 片段
        $segments = [];
        $re = '/\{(\d+)\.\.(\d+)(?:\.\.(\d+))?\}/';
        $idx = 0;
        $replaced = preg_replace_callback($re, function ($m) use (&$segments, &$idx) {
            $start = (int) $m[1];
            $end = (int) $m[2];
            $step = isset($m[3]) ? max(1, (int) $m[3]) : 1;
            $list = [];
            if ($start <= $end) {
                for ($i = $start; $i <= $end; $i += $step) {
                    $list[] = (string) $i;
                }
            } else {
                for ($i = $start; $i >= $end; $i -= $step) {
                    $list[] = (string) $i;
                }
            }
            $segments[] = $list;

            return '%%SEG' . ($idx++) . '%%';
        }, $pattern);

        // 若没有匹配成功，原样返回
        if ($replaced === null || empty($segments)) {
            return [$pattern];
        }

        // 生成笛卡尔积
        $results = [''];
        $parts = preg_split('/(%%SEG\d+%%)/', $replaced, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $part) {
            if (preg_match('/^%%SEG(\d+)%%$/', $part, $m)) {
                $segIdx = (int) $m[1];
                $newResults = [];
                foreach ($results as $prefix) {
                    foreach ($segments[$segIdx] as $val) {
                        $newResults[] = $prefix . $val;
                    }
                }
                $results = $newResults;
            } else {
                // 普通文本拼接
                foreach ($results as &$prefix) {
                    $prefix .= $part;
                }
                unset($prefix);
            }
        }

        return $results;
    }

    // 页面
    public function index(Request $request): Response
    {
        return raw_view('static_cache/index');
    }

    // 手动刷新（优先按URL策略入队；无策略时再按scope+pages入队）
    public function refresh(Request $request): Response
    {
        $scope = (string) $request->post('scope', 'all'); // all/index/list/post/url
        $pages = (int) $request->post('pages', 1); // 避免默认大量分页
        $url = (string) $request->post('url', '');
        $jobId = 'static_' . date('Ymd_His') . '_' . substr((string) microtime(true), -3);

        try {
            if ($scope === 'url' && $url) {
                publish_static(['type' => 'url', 'value' => $url, 'options' => ['force' => true, 'job_id' => $jobId]]);
            } else {
                $strategies = (array) (blog_config('static_url_strategies', [], true) ?: []);

                if (!empty($strategies)) {
                    foreach ($strategies as $it) {
                        $u = (string) ($it['url'] ?? '');
                        if ($u === '') {
                            continue;
                        }
                        if (empty($it['enabled'])) {
                            continue;
                        }
                        $minify = !empty($it['minify']);
                        // 区间展开：支持 {a..b} 与 {a..b..step}，以及多个区间组合
                        $urls = $this->expandUrlPatterns($u);
                        foreach ($urls as $eu) {
                            publish_static([
                                'type' => 'url',
                                'value' => $eu,
                                'options' => ['force' => true, 'job_id' => $jobId, 'minify' => $minify],
                            ]);
                        }
                    }
                } else {
                    // 无策略时回退到 scope 模式；默认仅1页，避免大量分页
                    publish_static(['type' => 'scope', 'value' => $scope, 'options' => ['pages' => max(1, $pages), 'force' => true, 'job_id' => $jobId]]);
                    if ($scope === 'all' || $scope === 'post') {
                        publish_static(['type' => 'scope', 'value' => 'post', 'options' => ['force' => true, 'job_id' => $jobId]]);
                    }
                }
            }

            return json(['success' => true, 'job_id' => $jobId]);
        } catch (Throwable $e) {
            return json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // URL策略：读取（为空则提供默认：各页面第一页）
    public function strategiesGet(Request $request): Response
    {
        $list = (array) (blog_config('static_url_strategies', [], true) ?: []);
        if (empty($list)) {
            $list = [
                ['url' => '/',             'enabled' => 1, 'minify' => 1],
                ['url' => '/page/1',       'enabled' => 1, 'minify' => 1],
                ['url' => '/link',         'enabled' => 1, 'minify' => 1],
                ['url' => '/link/page/1',  'enabled' => 1, 'minify' => 1],
            ];
        }

        return json(['success' => true, 'data' => $list]);
    }

    // URL策略：保存
    public function strategiesSave(Request $request): Response
    {
        $json = $request->post('list', '[]');
        $list = json_decode($json, true);
        if (!is_array($list)) {
            return json(['success' => false, 'message' => '参数格式错误']);
        }
        // 归一化
        $norm = [];
        foreach ($list as $it) {
            $u = trim((string) ($it['url'] ?? ''));
            if ($u === '') {
                continue;
            }
            if ($u[0] !== '/') {
                $u = '/' . $u;
            }
            $norm[] = [
                'url' => $u,
                'enabled' => !empty($it['enabled']) ? 1 : 0,
                'minify' => !empty($it['minify']) ? 1 : 0,
            ];
        }
        blog_config('static_url_strategies', $norm, true, true, true);

        return json(['success' => true]);
    }

    // URL策略：扫描文章并追加到策略列表
    public function strategiesScanPosts(Request $request): Response
    {
        $exist = (array) (blog_config('static_url_strategies', [], true) ?: []);
        $exists = [];
        foreach ($exist as $it) {
            $u = (string) ($it['url'] ?? '');
            if ($u) {
                $exists[$u] = true;
            }
        }

        $posts = Post::where('status', 'published')->select(['slug', 'id'])->get();
        $added = 0;
        foreach ($posts as $post) {
            $keyword = $post->slug ?? $post->id;
            $u = '/post/' . $keyword;
            if (!isset($exists[$u])) {
                $exist[] = ['url' => $u, 'enabled' => 1, 'minify' => 1];
                $exists[$u] = true;
                $added++;
            }
        }
        blog_config('static_url_strategies', $exist, true, true, true);

        return json(['success' => true, 'added' => $added, 'total' => count($exist)]);
    }

    // 查询进度：返回最新或指定 job_id 的进度
    public function progress(Request $request): Response
    {
        $jobId = (string) $request->get('job_id', '');
        if ($jobId === '') {
            $jobId = (string) (cache('static_progress_latest') ?: '');
        }
        if ($jobId === '') {
            return json(['success' => true, 'data' => null]);
        }
        $data = cache('static_progress_' . $jobId);
        $history = cache('static_progress_history') ?: [];

        return json(['success' => true, 'data' => $data, 'job_id' => $jobId, 'history' => $history]);
    }

    // 获取配置（AJAX）
    public function get(Request $request): Response
    {
        $data = [
            'static_base_url' => (string) blog_config('static_base_url', '', true),
            'site_scheme'     => (string) blog_config('site_scheme', 'http', true),
            'site_host'       => (string) blog_config('site_host', '127.0.0.1', true),
            'site_port'       => (int) blog_config('site_port', 8787, true),
        ];

        return json(['success' => true, 'data' => $data]);
    }

    // 保存配置（AJAX）
    public function save(Request $request): Response
    {
        $cfg = [
            'static_base_url' => (string) $request->post('static_base_url', ''),
            'site_scheme'     => (string) $request->post('site_scheme', 'http'),
            'site_host'       => (string) $request->post('site_host', '127.0.0.1'),
            'site_port'       => (int) $request->post('site_port', 8787),
        ];

        // 归一化
        $cfg['static_base_url'] = trim($cfg['static_base_url']);
        if ($cfg['static_base_url'] !== '') {
            $cfg['static_base_url'] = rtrim($cfg['static_base_url'], '/');
        }
        $scheme = strtolower($cfg['site_scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'http';
        }
        $cfg['site_scheme'] = $scheme;
        if ($cfg['site_port'] <= 0) {
            $cfg['site_port'] = $scheme === 'https' ? 443 : 80;
        }

        blog_config('static_base_url', $cfg['static_base_url'], true, true, true);
        blog_config('site_scheme', $cfg['site_scheme'], true, true, true);
        blog_config('site_host', $cfg['site_host'], true, true, true);
        blog_config('site_port', $cfg['site_port'], true, true, true);

        return json(['success' => true]);
    }
}
