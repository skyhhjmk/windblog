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

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

功能变更概览（最近更新）

★ 在线用户统计系统（v1.4.0+）

- 新增 OnlineUserService 服务（app/service/OnlineUserService.php）
- 新增 OnlineController 控制器（app/controller/OnlineController.php）
- 新增 OnlineWebSocketController 控制器（app/controller/OnlineWebSocketController.php）
- 基于 webman/push 插件实现 WebSocket 实时通信
- 支持已登录用户和访客（guest）在线统计
- 使用 Redis Sorted Set 存储在线用户数据
- 支持用户上线、下线、心跳、在线列表、在线人数、多维度活跃统计
- 支持 Pusher Protocol 的 presence 频道（presence-online）
- 提供 RESTful API 接口和实时推送事件
- 自动清理过期用户（5分钟超时）

★ AI 摘要系统（v1.3.0+）

- 新增 AiSummaryWorker 进程（app/process/AiSummaryWorker.php）
- 新增 AISummaryService 服务（app/service/AISummaryService.php）
- 新增 AiProviderInterface 接口与提供者系统（app/service/ai/）
- 新增数据库表：ai_providers、ai_polling_groups、ai_polling_group_providers
- posts 表新增 ai_summary 字段，用于存储 AI 生成的文章摘要
- 支持多提供商轮询（OpenAI/Claude/Gemini/本地模型等）
- 状态跟踪：Redis 存储 AI 元数据（enabled/status/error/provider/usage）
- 任务类型：summarize（文章摘要）、generic（chat/generate/translate）

★ OAuth 2.0 集成（v1.2.0+）

- 新增 user_oauth_bindings 表（支持 GitHub、Google、WeChat）
- 新增 OAuthService（app/service/OAuthService.php）
- 路由：/oauth/{provider}/redirect、/oauth/{provider}/callback
- 支持已登录用户绑定多个第三方账号
- 未注册用户首次 OAuth 登录时自动创建账号

★ 邮件系统增强

- MailWorker 支持多提供商轮询（weighted/rr 策略）
- 故障切换：平台失败时自动切换备用平台
- PHPMailer DSN 配置支持

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

核心架构模式

1. 多驱动缓存（CacheService）
  - 驱动：redis / apcu / memcached / memory / none
  - 高级特性：负缓存、过期抖动、防击穿锁、忙等待、故障自愈
  - blog_config_* 键特殊处理：跳过负缓存以避免启动问题

2. 消息队列（MQService + RabbitMQ）
  - 统一 MQService 管理连接/通道（单例 + 重连 + 健康检查）
  - 通用 DLX/DLQ 模式（x-dead-letter-exchange + x-retry-count）
  - Worker 进程：MailWorker、StaticGenerator、HttpCallback、LinkMonitor、ImportProcess、AiSummaryWorker
  - 有限重试：消息失败后重试 2 次，第 3 次进入死信队列

3. 静态化生成（StaticGenerator + StaticCacheRedirect）
  - 中间件前置拦截：GET 请求优先检查 public/cache/static/*.html
  - 消费者生成：HTTP 自调用 → 文件落盘
  - 增量生成：每小时检查最近 24h 更新的文章
  - 进度跟踪：job_id 机制用于前端查询

4. 视图与主题（TwigTemplateService）
  - 主题选择：blog_config("theme", "default") → app/view/{theme}/
  - 扩展系统：app/view/extension/（filter、function、test）
  - 钩子系统：pre_render / post_render
  - 缓存：TWIG_CACHE_ENABLE、TWIG_CACHE_PATH

5. OAuth 2.0（OAuthService）
  - 提供商：GitHub、Google、WeChat（可扩展）
  - user_oauth_bindings 表（加密存储 token）
  - 支持绑定/解绑多个第三方账号

6. AI 摘要（AiSummaryWorker）★
  - AiProviderInterface：统一接口（summarize/chat/generate/translate）
  - 提供者：LocalEchoProvider（测试）+ 可扩展（OpenAI/Claude 等）
  - 状态管理：Redis ai_meta:{post_id}（enabled/status/error/provider/usage）
  - 轮询组：支持多提供商负载均衡

7. 在线用户统计（OnlineUserService + webman/push）★
  - OnlineUserService：统一管理在线用户数据（userOnline/userOffline/userHeartbeat）
  - 存储结构：Redis Sorted Set（online_users/online_guests） + String（用户信息）
  - 支持已登录用户和访客分开统计，自动过期清理（5分钟超时）
  - 心跳机制：延长在线状态，建议每 60 秒发送一次
  - Push 频道：presentce-online（在线统计）、online-stats（统计事件）
  - 多维度统计：总在线数、已登录用户数、访客数、1/5/15分钟活跃用户
  - 广播功能：broadcastOnlineStats() 向所有连接客户端推送在线状态

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

数据流典型场景

场景 1：文章发布 → 静态化

1. 用户在后台发布文章（POST /app/admin/...）
2. Post 模型保存后触发事件（Illuminate\Events）
3. 事件监听器向 RabbitMQ 投递静态化任务：{type: "url", value: "/post/slug"}
4. StaticGenerator 消费消息 → HTTP 自调用 → 渲染 HTML → 落盘到 public/cache/static/post/slug.html
5. 前台访问 /post/slug 时，StaticCacheRedirect 中间件优先返回静态文件

场景 2：用户注册 → 邮件激活

1. 用户提交注册表单（POST /user/register）
2. UserController 创建用户记录，生成 activation_token
3. 向 RabbitMQ 投递邮件任务：{to, subject, body, provider: "smtp1"}
4. MailWorker 消费消息 → 选择提供商（weighted 策略）→ PHPMailer 发送
5. 失败时切换备用提供商 → 重试 2 次 → 仍失败则进入 DLQ
6. 用户点击激活链接（GET /user/activate?token=xxx）→ 验证 token → 更新 email_verified_at

场景 3：文章 AI 摘要生成★

1. 后台文章编辑页点击"生成 AI 摘要"按钮（AJAX POST）
2. AISummaryService::enqueue() → 向 RabbitMQ 投递任务：{task_type: "summarize", post_id: 123, provider: "openai"}
3. AiSummaryWorker 消费消息 → 标记状态为 refreshing（Redis）
4. 调用 AiProvider::summarize() → 发送 HTTP 请求到 AI API
5. 成功：更新 posts.ai_summary → 标记状态为 done
6. 失败：标记状态为 failed + error 信息 → 重试机制（x-retry-count）
7. 前台文章列表优先使用 ai_summary 字段展示

场景 4：OAuth 登录

1. 用户点击"使用 GitHub 登录"（GET /oauth/github/redirect）
2. OAuthService 生成授权 URL → 跳转到 GitHub
3. 用户授权后回调（GET /oauth/github/callback?code=xxx）
4. OAuthService 交换 code → access_token → 获取用户信息
5. 检查 user_oauth_bindings 表：存在则登录，不存在则自动注册 + 绑定
6. 创建 Session 或 JWT → 跳转到首页

场景 5：在线用户统计★

1. 用户访问网站，前端初始化 Push WebSocket 连接（ws://host:3131）
2. WebSocket 连接成功后，调用 POST /online/connect（OnlineWebSocketController::connect）
3. 根据 session 判断用户状态：
  - 已登录：OnlineUserService::userOnline($userId, $userInfo) → Redis ZADD online_users
  - 访客：OnlineUserService::guestOnline($sessionId, $guestInfo) → Redis ZADD online_guests
4. broadcastOnlineStats() → Push API 向 online-stats 频道推送 stats-updated 事件
5. 所有连接客户端接收到实时在线统计数据（total_online、logged_users、guests、活跃用户）
6. 前端每 60 秒发送心跳（POST /online/heartbeat）→ 延长在线状态
7. 用户关闭页面或断开连接 → POST /online/disconnect → userOffline/guestOffline
8. 后台定期执行 cleanExpiredUsers/cleanExpiredGuests，清理超过 5 分钟未发送心跳的用户

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

开发者工作流

调试技巧

- APP_DEBUG=true：详细错误信息 + Twig 调试模式
- DebugToolkit 中间件：请求耗时、SQL 查询、HTTP 客户端
- Log::info/error()：日志写入 runtime/logs/
- ?no_cache=1：绕过静态缓存查看动态渲染
- ?preview=1：绕过静态缓存（同上）

常用开发命令

- php console config:db：交互式配置数据库
- php console config:redis：交互式配置 Redis
- php console config:admin:re-init：重置管理员账号
- php console elastic:rebuild：重建 ES 索引
- php console static:generate：手动触发静态化生成

测试

- composer test：全量测试
- composer test:filter "CacheService"：过滤测试
- composer test:coverage：生成覆盖率报告（HTML）
- 测试环境推荐：CACHE_DRIVER=memory（避免 Redis 依赖）

代码质量

- composer cs:fix：自动修复代码风格
- composer analyse：PHPStan 静态分析
- composer check：一键运行 cs:check + analyse + test

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

重要注意事项

⚠️ 后台配置不完整
许多网站设置需直接修改数据库 settings 表（而非后台界面）

⚠️ 浮动链接 AFF 替换
FloLinkService.php 包含内置 AFF 规则，使用前需修改 rewriteAffiliateLink() 方法

⚠️ 常驻进程特性
代码变更必须 reload/restart 才能生效；静态变量、单例在进程生命周期内持久化

⚠️ 安装流程
首次启动后访问 /app/admin（首页无安装检测）；安装完成后必须重启以注册附加进程

⚠️ PostgreSQL 优先
项目从 MySQL 重构为 PostgreSQL，推荐使用 PostgreSQL 以获得最佳性能与特性支持

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

深度技术文档：请参阅 WARP_FUNCTIONS.md

本文档（WARP.md）提供快速参考与开发指南；WARP_FUNCTIONS.md 提供完整的架构分析、数据流说明、代码示例及最佳实践。

关键章节索引（WARP_FUNCTIONS.md）：

-
  1. 博客基础功能（模型、控制器、路由）
-
  2. 缓存架构（多驱动、高级特性）
-
  3. 消息队列架构（MQService + Worker 进程）
-
  4. 静态化生成系统
-
  5. 视图与主题系统
-
  6. 搜索系统（ElasticSearch + 数据库回退）
-
  7. 安全与中间件
-
  8. OAuth 2.0 系统
-
  9. AI 摘要系统★
-
  10. 在线用户统计系统★
-
  11. 性能监控与优化
-
  12. 插件系统
-
  13. 友链系统（普通友链 + FloLink + WindConnect API）
-
  14. 媒体库
-
  15. 数据库架构（PostgreSQL/MySQL/SQLite + 迁移）
-
  16. 命令行工具
-
  17. 测试覆盖
-
  18. 代码质量工具
-
  19. Docker 部署
-
  20. 开发工作流最佳实践
-
  21. 已知限制与注意事项
