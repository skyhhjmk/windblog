# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

仓库概览
- 技术栈：PHP 8.3+、Webman（基于 WorkerMan 的常驻进程框架）、Twig、PostgreSQL（主库），可选 Redis（缓存）、RabbitMQ（消息队列）、ElasticSearch（搜索）
- 运行模式：常驻进程。代码变更需要重载/重启进程才能生效
- 首次安装：首次启动后访问 /app/admin 完成安装；根目录存在 .env 表示“已安装”，并会启用更多工作进程

先决条件与初始化
- PHP：>= 8.3，建议启用 pdo_pgsql 扩展（PostgreSQL）
- 依赖安装：composer install（生产环境可用 --no-dev --optimize-autoloader）
- 环境配置：复制 .env.example 为 .env，并根据环境修改 DB_*、CACHE_DRIVER、REDIS_* 等

常用命令
- 依赖安装
  - composer install
  - 生产安装：composer install --no-dev --optimize-autoloader

- 启动与管理（Linux/macOS）
  - 前台启动：php start.php start
  - 守护进程：php start.php start -d
  - 停止：php start.php stop
  - 平滑重载：php start.php reload
  - 重启：php start.php restart
  - 状态：php start.php status

- 启动与管理（Windows / PowerShell）
  - 包装脚本启动：php windows.php
  - 或直接运行：windows.bat

- 控制台命令
  - 查看命令列表：php console list
  - 数据库配置：php console config:db
  - Redis 配置：php console config:redis
  - 重新初始化管理员：php console config:admin:re-init

- 测试
  - 全量测试：composer test  或  vendor/bin/phpunit
  - 单个文件：vendor/bin/phpunit tests/Unit/Services/CacheServiceTest.php
  - 按名称过滤：composer test:filter "CacheService"  或  vendor/bin/phpunit --filter CacheService
  - 生成覆盖率（HTML）：composer test:coverage

- 静态分析与代码风格
  - PHPStan（级别见 phpstan.neon.dist）：composer analyse
  - 风格检查（dry-run）：composer cs:check
  - 自动修复：composer cs:fix
  - 组合校验：composer check

Docker 与 Compose
- 构建镜像：docker build -t windblog .
- 运行镜像：docker run -d -p 8787:8787 windblog
- 一键启动全栈（App + Postgres + Redis + RabbitMQ [+ ES]）：docker compose up -d
  - 根目录 docker-compose.yml：应用启动命令为 php start.php start
  - 运行时镜像 Compose：compose/docker-compose.yml（使用 ghcr.io/skyhhjmk/windblog:master）

高层架构（要点）
- 进程模型（config/process.php）
  - webman：HTTP 服务，监听 0.0.0.0:8787，worker 数量为 cpu_count()*4
  - monitor：文件/内存监控（Unix 下自动热重载；Windows 由 windows.php 模拟）
  - task：定时任务处理
  - performance：仅当 CACHE_DRIVER=redis 时启用
  - 存在 .env 后附加进程：
    - http_callback：异步 HTTP 回调
    - link_monitor：友链监控
    - importer：WordPress 导入
    - static_generator：静态页面生成（落盘到 public/static，使用 RabbitMQ，带 DLX/DLQ 与重试）
    - mail_worker：邮件发送（多提供商策略与故障切换，带 DLX/DLQ 与有限重试）

- 路由结构（config/route.php）
  - 默认路由关闭，均为显式路由
  - 主要模式：
    - 文章：/post/{keyword} 与 /post/{keyword}.html
    - 首页与分页：/ 与 /page/{n}
    - 分类/标签（长短路由）：/category/{slug}、/c/{slug}；/tag/{slug}、/t/{slug}
    - 搜索：/search（含分页）
    - 友链：/link、/link/page/{n}、/link/goto/{id}、/link/info/{id}
    - 管理后台：/app/admin（webman/admin 插件）
    - API v1：/api/v1/posts、/api/v1/post/{id}

- 视图与主题
  - app/service/TwigTemplateService.php 实现 Webman\View 接口
  - 通过 blog_config("theme", "default") 选择主题；模板位于 app/view/{theme}/...
  - app/view/extension 提供 Twig 扩展；支持模板变量/HTML 过滤及渲染生命周期钩子

- 缓存
  - 驱动：redis、apcu、memcached、memory、none；支持 CACHE_PREFIX
  - 特性：负缓存、过期抖动、防击穿锁、忙等待、非严格模式降级
  - 特殊：blog_config_* 键跳过负缓存以避免启动问题

- 消息队列（RabbitMQ）
  - MQService 统一管理连接/通道与队列初始化（含 DLX/DLQ）
  - 消费者：MailWorker、HttpCallback、LinkMonitor、StaticGenerator、ImportProcess
  - 策略：有限重试后入死信；定时健康检查

- 静态化生成（app/process/StaticGenerator.php）
  - 消费队列，按 URL 或范围（index/list/post/all）渲染静态 HTML 至 public/static

- 管理后台与中间件
  - 管理后台代码/资源位于 plugin/admin；公开资源在 plugin/admin/public
  - 核心中间件：AuthCheck、CSRFMiddleware、DebugToolkit、IpChecker、Lang、PluginSupport、StaticCacheRedirect、StaticFile

关键配置触点
- 环境变量（.env，参见 .env.example）
  - DB_DEFAULT、DB_PGSQL_*、CACHE_DRIVER、各类缓存细节、REDIS_*，以及 CSP/HSTS 与 Twig 缓存开关
- 服务器（config/server.php）：PID/状态/日志文件、max_package_size 等
- 数据库（config/database.php）：默认 pgsql，同时提供 mysql 与 sqlite 配置
- Redis（config/redis.php）：default 与 cache 两个逻辑库及连接池参数

在本仓库工作的注意事项
- 常驻进程：代码变更后使用 php start.php reload；Windows 环境推荐使用 php windows.php 以获得文件变更感知与重载
- 首次安装：启动后访问 /app/admin 完成安装；安装完成后重启以初始化附加进程
- 本地测试可参考 CI：复制 .env.example 为 .env，并设置 CACHE_DRIVER=memory
- 优先使用 composer 脚本（analyse、cs:check、cs:fix、test:filter）以获得一致的工具链体验
