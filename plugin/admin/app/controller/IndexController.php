<?php

namespace plugin\admin\app\controller;

use app\model\Media;
use app\model\Post;
use app\model\Setting;
use plugin\admin\app\common\Util;
use plugin\admin\app\model\User;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;
use Workerman\Worker;

class IndexController
{
    /**
     * 无需登录的方法
     *
     * @var string[]
     */
    protected array $noNeedLogin = ['index'];

    /**
     * 不需要鉴权的方法
     *
     * @var string[]
     */
    protected $noNeedAuth = ['dashboard', 'getSiteInfo'];

    /**
     * 后台主页
     *
     * @param Request $request
     *
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function index(Request $request): Response
    {
        clearstatcache();
        // 如果没有安装锁文件，并且在容器内，则显示安装页面（前端自动跳过环境检查和数据库初始化）
        if ((!is_install_lock_exists() && container_info()['in_container']) || !is_install_lock_exists() && !container_info()['in_container']) {
            return raw_view('index/install');
        }
        $admin = admin();
        if (!$admin) {
            $name = 'system_config';
            $config = Setting::where('key', $name)->value('value');
            $config = $config ? json_decode($config, true) : null;
            $title = ($config && isset($config['logo']['title'])) ? $config['logo']['title'] : 'webman admin';
            $logo = ($config && isset($config['logo']['image'])) ? $config['logo']['image'] : '/app/admin/admin/images/logo.png';

            return raw_view('account/login', ['logo' => $logo, 'title' => $title]);
        }

        return raw_view('index/index');
    }

    /**
     * 仪表板
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function dashboard(Request $request): Response
    {
        // 今日新增用户数（UTC时间）
        $today_user_count = User::where('created_at', '>', utc_now()->startOfDay())->count();
        // 7天内新增用户数
        $day7_user_count = User::where('created_at', '>', utc_now()->subDays(7))->count();
        // 30天内新增用户数
        $day30_user_count = User::where('created_at', '>', utc_now()->subDays(30))->count();
        // 总用户数
        $user_count = User::count();
        // 总文章数
        $post_count = Post::count();
        // 待发布文章数
        $draft_count = Post::where('status', 'draft')->count();
        // 总媒体数
        $media_count = Media::count();

        // 根据当前数据库类型获取版本信息
        $driver = config('database.default');
        $version_info = 'unknown';
        try {
            switch ($driver) {
                case 'mysql':
                    $version = Util::db()->select('select VERSION() as version');
                    $version_info = $version[0]->version ?? 'unknown';
                    break;
                case 'pgsql':
                    $version = Util::db()->select('select version() as version');
                    $version_info = $version[0]->version ?? 'unknown';
                    break;
                case 'sqlite':
                    $version = Util::db()->select('select sqlite_version() as version');
                    $version_info = 'SQLite ' . ($version[0]->version ?? 'unknown');
                    break;
            }
        } catch (Throwable $e) {
            $version_info = 'unknown';
        }

        $day7_detail = [];
        $now = utc_now();
        for ($i = 0; $i < 7; $i++) {
            $date = $now->copy()->subDays($i);
            $dateStr = $date->format('Y-m-d');
            $day7_detail[substr($dateStr, 5)] = User::where('created_at', '>=', $date->startOfDay())
                ->where('created_at', '<=', $date->endOfDay())->count();
        }

        $windblog_version = get_windblog_version()['version'] . ' (' . get_windblog_version()['commit'] . ' + ' . get_windblog_version()['build_time'] . ' + ' . get_windblog_version()['build_number'] . ')';

        return raw_view('index/dashboard', [
            'today_user_count' => $today_user_count,
            'day7_user_count' => $day7_user_count,
            'day30_user_count' => $day30_user_count,
            'user_count' => $user_count,
            'post_count' => $post_count,
            'draft_count' => $draft_count,
            'media_count' => $media_count,
            'php_version' => PHP_VERSION,
            'workerman_version' => Worker::VERSION,
            'webman_version' => Util::getPackageVersion('workerman/webman-framework'),
            'admin_version' => Util::getPackageVersion('webman/admin'),
            'mysql_version' => $version_info,
            'os' => PHP_OS,
            'day7_detail' => array_reverse($day7_detail),
            'windblog_version' => $windblog_version,
        ]);
    }

    /**
     * 获取网站标题和Logo用于后台显示
     *
     * @return Response
     */
    public function getSiteInfo(): Response
    {
        // 从blog_config获取网站信息
        $siteInfo = [
            'title' => blog_config('title', 'WindBlog', true),
            'site_url' => blog_config('site_url', '', true),
            'description' => blog_config('description', '', true),
            'favicon' => blog_config('favicon', '', true),
            'icp' => blog_config('icp', '', true),
            'beian' => blog_config('beian', '', true),
            'footer_txt' => blog_config('footer_txt', '', true),
        ];

        return json($siteInfo);
    }
}
