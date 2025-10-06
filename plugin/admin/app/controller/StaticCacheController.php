<?php
namespace plugin\admin\app\controller;

use support\Request;
use support\Response;
use app\model\Post;

/**
 * 静态缓存设置
 * - 管理 StaticGenerator 自调用所需基址配置
 * - 键：static_base_url / site_scheme / site_host / site_port
 */
class StaticCacheController extends Base
{
    // 页面
    public function index(Request $request): Response
    {
        return raw_view('static_cache/index');
    }

    // 手动刷新（投递范围任务，走分段覆盖策略）
    public function refresh(Request $request): Response
    {
        $scope = (string)$request->post('scope', 'all'); // all/index/list/post/url
        $pages = (int)$request->post('pages', 50);
        $url   = (string)$request->post('url', '');
        $jobId = 'static_' . date('Ymd_His') . '_' . substr((string)microtime(true), -3);

        try {
            if ($scope === 'url' && $url) {
                publish_static(['type' => 'url', 'value' => $url, 'options' => ['force' => true, 'job_id' => $jobId]]);
            } else {
                // 1) 先发布全量范围任务（包含文章）
                publish_static(['type' => 'scope', 'value' => $scope, 'options' => ['pages' => $pages, 'force' => true, 'job_id' => $jobId]]);
                if ($scope === 'all' || $scope === 'post') {
                    // 显式确保文章范围入队
                    publish_static(['type' => 'scope', 'value' => 'post', 'options' => ['force' => true, 'job_id' => $jobId]]);
                }
                // 2) 再按URL策略逐条投递
                $strategies = (array)(blog_config('static_url_strategies', [], true) ?: []);
                foreach ($strategies as $it) {
                    $u = (string)($it['url'] ?? '');
                    if (!$u) continue;
                    $enabled = !empty($it['enabled']);
                    if (!$enabled) continue;
                    $minify = !empty($it['minify']);
                    publish_static([
                        'type' => 'url',
                        'value' => $u,
                        'options' => ['force' => true, 'job_id' => $jobId, 'minify' => $minify]
                    ]);
                }
            }
            return json(['success' => true, 'job_id' => $jobId]);
        } catch (\Throwable $e) {
            return json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // URL策略：读取（为空则提供默认：各页面第一页）
    public function strategiesGet(Request $request): Response
    {
        $list = (array)(blog_config('static_url_strategies', [], true) ?: []);
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
            $u = trim((string)($it['url'] ?? ''));
            if ($u === '') continue;
            if ($u[0] !== '/') $u = '/' . $u;
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
        $exist = (array)(blog_config('static_url_strategies', [], true) ?: []);
        $exists = [];
        foreach ($exist as $it) {
            $u = (string)($it['url'] ?? '');
            if ($u) $exists[$u] = true;
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
        $jobId = (string)$request->get('job_id', '');
        if ($jobId === '') {
            $jobId = (string)(cache('static_progress_latest') ?: '');
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
            'static_base_url' => (string)blog_config('static_base_url', '', true),
            'site_scheme'     => (string)blog_config('site_scheme', 'http', true),
            'site_host'       => (string)blog_config('site_host', '127.0.0.1', true),
            'site_port'       => (int)blog_config('site_port', 8787, true),
        ];
        return json(['success' => true, 'data' => $data]);
    }

    // 保存配置（AJAX）
    public function save(Request $request): Response
    {
        $cfg = [
            'static_base_url' => (string)$request->post('static_base_url', ''),
            'site_scheme'     => (string)$request->post('site_scheme', 'http'),
            'site_host'       => (string)$request->post('site_host', '127.0.0.1'),
            'site_port'       => (int)$request->post('site_port', 8787),
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
        blog_config('site_scheme',     $cfg['site_scheme'],     true, true, true);
        blog_config('site_host',       $cfg['site_host'],       true, true, true);
        blog_config('site_port',       $cfg['site_port'],       true, true, true);

        return json(['success' => true]);
    }

}