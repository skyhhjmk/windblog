# 配置项参考（Config Reference）

> 本文档汇总常用配置项的含义，方便在调整 `.env` 或数据库 `settings` 表时查阅。实际生效值以 `.env`、`config/*.php` 和数据库为准。

## 1. 环境 / 缓存相关配置

> 这些配置通常通过 `.env` 设置，示例请参考 `.env.example` 以及 `config/server.php`、`config/database.php`、
`config/redis.php`。

### DB_DEFAULT

- 默认数据库连接名称。
- 必须与 `config/database.php` 中定义的某个连接名一致（例如 `pgsql`）。

### DB_PGSQL_*

- PostgreSQL 连接相关参数（如主机、端口、数据库名、用户名、密码等）。
- 对应 `config/database.php` 中的 `pgsql` 连接配置。

### CACHE_DRIVER

- 全局缓存驱动。
- 支持的值（参考 `WARP.md` 与缓存实现）：`redis` / `apcu` / `memcached` / `memory` / `none`。
- 本地测试推荐：`memory`（避免依赖 Redis 服务）。

### CACHE_PREFIX

- 缓存 key 前缀，用于隔离多实例或者多环境下的缓存数据。
- 留空时直接使用原始 key；设置后所有缓存 key 会自动加此前缀。

### REDIS_*

- Redis 连接相关参数（主机、端口、密码、库编号等）。
- 对应 `config/redis.php` 中的 `default` 与 `cache` 连接池配置。

### TWIG_CACHE_ENABLE

- 是否启用 Twig 模板缓存。
- `true`：启用磁盘缓存，提高模板渲染性能，适合生产环境。
- `false`：关闭缓存，便于开发调试。

### TWIG_CACHE_PATH

- Twig 模板缓存目录路径。
- 仅在 `TWIG_CACHE_ENABLE` 启用时生效。

### APP_DEBUG

- 是否开启调试模式。
- `true`：输出详细错误信息，同时开启 Twig 调试模式（参见 `WARP.md` 中“调试技巧”）。
- `false`：生产环境推荐，避免泄露内部错误细节。

---

## 2. 评论系统配置（settings 表，经由 blog_config）

> 所有评论相关配置存储在数据库 `settings` 表，通过 `blog_config()` 函数从数据库读取，可在后台动态修改。默认值和示例 SQL 见
`config/comment.php`。

### comment_min_length

- 评论最小长度（字符数）。
- 默认：`2`。

### comment_max_length

- 评论最大长度（字符数）。
- 默认：`1000`。

### comment_max_urls

- 单条评论允许包含的 URL 最大数量，用于减少垃圾评论。
- 默认：`3`。

### comment_max_quote_length

- 引用文本（如引用上一条评论内容）的最大长度（字符数）。
- 超出部分会被截断，避免引用链过长。
- 默认：`200`。

### comment_duplicate_window

- 重复评论检查时间窗口（秒）。
- 在该时间窗口内提交完全相同内容会被认定为重复评论。
- 默认：`300`（5 分钟）。

### comment_frequency_window

- 评论频率限制时间窗口（秒）。
- 与 `comment_max_frequency` 配合使用，控制单位时间内允许的评论次数。
- 默认：`60`（1 分钟）。

### comment_max_frequency

- 在 `comment_frequency_window` 时间窗口内允许的最大评论数。
- 超出该次数的请求会被视为频率过高。
- 默认：`3`。

### comment_moderation

- 是否需要人工审核。
- `true`：新评论进入待审核队列，由管理员审核后再公开。
- `false`：评论在通过基础校验后直接公开。
- 默认：`true`。

### comment_ai_moderation_enabled

- 是否启用 AI 审核。
- `true`：在人工审核前增加一层 AI 审核流程，用于初筛风险评论。
- `false`：仅依赖传统规则与人工审核。
- 默认：`false`。

### comment_ai_moderation_priority

- AI 审核优先级，数值越大表示优先级越高。
- 可用于与其他风控任务进行排序。
- 默认：`5`。

---

## 3. 其他与配置相关的触点

### 主题选择

- `blog_config("theme", "default")` 用于选择当前主题。
- 模板路径：`app/view/{theme}/...`，其中 `{theme}` 来自配置项 `theme`，默认值为 `default`。

### 缓存与性能相关

- `CACHE_DRIVER` 为 `redis` 时，会启用 Redis 缓存，并启用部分额外进程（如 `performance` 进程，参考 `WARP.md` 中“高层架构（要点）”）。
- 静态化、消息队列等子系统的行为也会受到 RabbitMQ / Redis / ElasticSearch 是否可用的影响，详细说明见 `WARP.md` 对应章节。

---

## 4. 站点信息与 SEO 配置

> 这些配置主要通过后台「站点设置 / SEO 设置」界面写入 `settings` 表，经由 `blog_config()` 读取。

### 基本站点信息

- **title**
  - 站点标题，显示在浏览器标题、页眉等位置。
  - 默认：`WindBlog`。
- **site_url**
  - 站点基础 URL（含协议），例如 `https://example.com`。
  - 多处用作站内链接、API 地址与友链互联站点信息的基准。
- **description**
  - 站点描述，作为首页/站点级 `meta description` 的默认值。
- **favicon**
  - 站点图标路径/URL，`ConfigController::set_site_info()` 会在更新后尝试生成 `public/favicon.ico`。
- **site_logo**
  - 站点 Logo 路径/URL，用于前台导航栏、SEO 分享图片等。
- **icp / beian / footer_txt**
  - 备案号、备案信息及页脚文案，主要在前台 footer 和后台管理页显示。
- **admin_email**
  - 管理员邮箱，用于友链互联、系统通知等展示/联系信息。

### SEO 相关

- **seo_title_suffix**
  - 页面标题统一后缀，例如 `- 我的博客`。
- **seo_default_description**
  - 当文章/页面未单独配置描述时的兜底 `meta description`。
- **seo_default_keywords**
  - 默认关键词列表（逗号分隔），在文章未设置 `seo_keywords` 时使用。
- **seo_default_image**
  - 默认分享图片 URL，当文章没有封面图时用于 OpenGraph/Twitter 卡片。
- **seo_twitter_card_type**
  - Twitter 卡片类型，通常为 `summary_large_image`。
- **seo_twitter_username**
  - Twitter 用户名，用于 `twitter:site` 等标签。
- **seo_organization_name / seo_organization_logo**
  - 结构化数据中组织名称与 Logo。

---

## 5. 嵌入代码配置

> 用于在不改动模板的情况下插入统计脚本、广告代码等，注意自行保证安全性。

- **embed_head**
  - 直接插入到 `</head>` 之前（在 `base.html.twig` 中调用 `{{ blog_config('embed_head')|raw }}`）。
  - 常用于统计脚本、全局样式、验证标签等。
- **embed_body_start**
  - 插入在 `<body>` 起始位置之后，适合放置全局浮动组件、顶部提示。
- **embed_body_end**
  - 插入在 `</body>` 前，适合放置统计脚本尾部代码、辅助脚本等。

---

## 6. 静态化与静态缓存配置

### StaticGenerator 远程访问与基础 URL

- **static_base_url**
  - 静态生成进程用于 HTTP 自调用的基础地址。
  - 若为空，则根据 `site_scheme` + `site_host` + `site_port` 自动拼接。
- **site_scheme / site_host / site_port**
  - 站点协议/主机名/端口，用于静态生成 HTTP 自调用和部分对外 URL。
  - `StaticGenerator::getBaseUrl()` 与 `StaticCacheController` 的配置页会使用。

### 静态 URL 策略与预热

- **static_url_strategies**
  - 数组结构，定义需要静态化的 URL 列表及是否启用、是否压缩：
  - 典型元素：`{ url: '/page/{1..5}', enabled: 1, minify: 1 }`。
  - 由后台 `StaticCacheController::strategiesSave()` 写入，用于批量入队静态化任务。
- **static_cache_warmup_urls**
  - 用于静态缓存预热的 URL 列表，`StaticCacheController::warmup()` 会遍历这些地址并发布静态任务。

### 增强静态缓存（EnhancedStaticCacheConfig）

- **static_cache_enabled**
  - 是否启用增强静态缓存系统。
- **static_cache_compression**
  - 是否启用静态文件压缩（JS/CSS 资源按策略进行压缩与替换）。
- **static_cache_strategies**
  - 增强版的 URL 策略配置，结构由 `EnhancedStaticCacheConfig` 定义；包括不同 URL 的缓存策略（public/private、是否最小化等）。
- **static_cache_warmup_urls**
  - 与上文相同，由增强缓存模块读取并用于预热。

### 静态缓存版本与统计（内部使用）

- **static_cache_version**（通过 `EnhancedStaticCacheConfig::updateCacheVersion()` 间接维护）
  - 当前静态缓存版本号，用于目录分片和缓存失效控制。
- **static_cache_stats**（结构化数据）
  - 记录静态缓存生成次数、最后生成时间等统计信息，主要用于后台监控界面。

---

## 7. RabbitMQ / 消息队列配置

> 所有 MQ 连接参数和队列命名均通过 `blog_config()` 管理。多数键有合理默认值，新部署时仅需在后台 MQ 配置页修正主机、端口和凭据。

### 连接参数（全局）

- **rabbitmq_host** / **rabbitmq_port** / **rabbitmq_user** / **rabbitmq_password** / **rabbitmq_vhost**
  - MQ 连接基础配置。
  - 由后台 `MqController`、`MailController` 等读取和测试连接。

### 静态化队列（StaticGenerator / publish_static）

- **rabbitmq_static_exchange**
- **rabbitmq_static_routing_key**
- **rabbitmq_static_queue**
- **rabbitmq_static_dlx_exchange**
- **rabbitmq_static_dlx_queue**
  - 控制静态化任务交换机、路由键、队列及死信队列命名。

### 邮件队列（MailWorker）

- **rabbitmq_mail_exchange** / **rabbitmq_mail_routing_key** / **rabbitmq_mail_queue**
- **rabbitmq_mail_dlx_exchange** / **rabbitmq_mail_dlx_queue**
  - 邮件发送任务队列命名，由后台 `MailController` 的配置页维护。

### 友链相关队列

- **rabbitmq_link_monitor_exchange / queue / routing_key / dlx_exchange / dlx_queue**
  - 友链监控（LinkMonitor）使用的 MQ 命名。
- **rabbitmq_link_connect_exchange / queue / routing_key / dlx_exchange / dlx_queue**
  - 友链互联（LinkConnectWorker）使用的 MQ 命名。
- **rabbitmq_link_push_exchange / queue / routing_key / dlx_exchange / dlx_queue**
  - 友链扩展信息推送（LinkPushWorker + LinkPushQueueService）使用的 MQ 命名。
- **rabbitmq_link_audit_exchange / queue / routing_key / dlx_exchange / dlx_queue**
  - 友链 AI 审核（LinkAIModerationService / LinkAuditWorker）队列命名。

### 其他工作队列

- **rabbitmq_http_callback_...**（如果存在）
  - HTTP 回调、在线用户统计、AI 摘要等 Worker 的队列命名遵循相同模式：`rabbitmq_{模块名}_{exchange|queue|routing_key|dlx_...}`
    ，含义与上类似。

> 说明：RabbitMQ 端口等数值配置在 `blog_config_get_from_db()` 中做了统一合法性检查（1–65535）。

---

## 8. 搜索 / Elasticsearch 配置

> 由后台 `ElasticController` 管理，配置键以 `es.` 前缀为主，经由 `blog_config('es.xxx', ...)` 读写。

### 连接与索引

- **es.enabled**
  - 是否启用 Elasticsearch 搜索，关闭时回退到数据库搜索。
- **es.host**
  - ES 服务地址，如 `http://127.0.0.1:9200`。
- **es.index**
  - 文章索引名称，默认为 `windblog-posts`，重建索引或同义词重建时会更新。
- **es.timeout**
  - 客户端请求超时时间（秒）。
- **es.basic.username / es.basic.password**
  - HTTP 基本认证用户名/密码。

### SSL 相关

- **es.ssl.ca_content**
  - CA 证书内容，用于验证 ES 服务端证书。
- **es.ssl.ignore_errors**
  - 是否忽略 SSL 错误（`true` 时跳过证书校验，不建议生产使用）。
- **es.ssl.client_cert_content / es.ssl.client_key_content**
  - 客户端证书与私钥内容，供双向 TLS 使用。

### 分词与同义词

- **es.analyzer**
  - 搜索分析器名称，如 `standard` 或自定义分词器，创建索引和 `_analyze` 预览时使用。
- **es.synonyms**
  - 同义词规则文本（多行，Elasticsearch `synonym_graph` 过滤器语法）。
  - 由后台「同义词管理」页面编辑并写入，`ElasticController::applySynonyms*` 系列方法会据此更新索引设置。

---

## 9. AI 摘要与通用 AI 配置

### 全局选择与 Prompt

- **ai_current_selection**
  - 当前选用的 AI 提供方或轮询组，格式：`provider:{id}` 或 `group:{id}`。
  - 由后台 AI 摘要 / AI 测试 / 评论 AI 审核等界面统一维护。
- **ai_summary_prompt**
  - 文章 AI 摘要生成提示词模板，`AiSummaryController::promptGet/Save` 读写。

### AI 测试与模板

- **ai_test_templates**
  - AI 测试页面中保存的常用 Prompt 模板列表（JSON 数组），每项包含 `id/name/prompt/task/...`。

---

## 10. 评论 AI 审核配置

> 与第 2 节的基础评论配置相辅相成，本节侧重 AI 审核相关高级配置。

- **comment_ai_moderation_enabled**
  - 是否启用评论 AI 审核（已在第 2 节简要说明，此处为复述）。
- **comment_ai_moderation_prompt**
  - 评论审核使用的 Prompt 模板，包含输出 JSON 格式、敏感词/谐音识别等规则。
- **comment_ai_moderation_temperature**
  - 调用 AI 提供方时使用的温度参数，默认 `0.1`，数值越大输出越“发散”。
- **comment_ai_moderation_model**
  - 指定使用的模型名称，留空时由当前 AI 提供方的默认模型决定。
- **comment_ai_moderation_failure_strategy**
  - 当 AI 调用失败时的策略：如 `approve`（默认批准）等。
- **comment_ai_auto_approve_on_pass**
  - 当 AI 结果为通过且置信度满足条件时是否自动将评论标记为已审核。
- **comment_ai_auto_approve_min_confidence**
  - 自动通过所需的最小置信度（0–1）。

---

## 11. 友链与 FloLink 相关配置

### 浮动链接（FloLink）

- **flolink_enabled**
  - 是否启用 FloLink 关键词浮动链接功能，关闭时不做内容替换。
- **flolink_affiliate_rewrite**
  - 是否启用联盟链接改写（针对特定站点，如雨云）。
- **flolink_affiliate_suffix**
  - 联盟链接路径后缀，例如默认 `github_`，用于规范 AFF 参数。

### 友链交换与互联（WindConnect）

- **link_exchange_enabled**
  - 是否开启传统友链交换申请入口。
- **link_exchange_requirements**
  - 申请友链的要求说明文本（展示给申请方）。
- **wind_connect_token**
  - WindConnect 互联协议的共享 token，用于双方请求鉴权。
- **wind_connect_ssl_verify**
  - WindConnect HTTP 请求是否校验 SSL 证书（`true` 推荐）。
- **link_connect_timeout**
  - 友链互联/推送 HTTP 请求的超时时间（秒）。

### 站点统计类

- **total_visits**
  - 站点总访问量计数，用于友链互联或站点统计信息输出。
- **start_date**
  - 站点上线日期，展示在对外能力说明中。

---

## 12. Slug 翻译与百度翻译配置

> 由 `ConfigController::get/set_slug_translate_config` 和 `SlugTranslateService` / `BaiduTranslateService` 使用。

- **baidu_translate_appid / baidu_translate_secret**
  - 百度翻译 API 的 AppID 与密钥。
- **slug_translate_mode**
  - Slug 翻译模式：
  - `baidu`：仅使用百度翻译。
  - `ai`：仅使用 AI 模型生成英文 Slug。
  - `auto`：默认模式，优先百度翻译，失败则回退到 AI。
- **slug_translate_ai_selection**
  - 指定用于生成 Slug 的 AI 提供者/轮询组（如 `provider:xxx` / `group:1`）。

---

## 13. OAuth 与第三方登录配置

> OAuth 配置以 `oauth_*` 键存储，`ConfigController::get_oauth_config / set_oauth_config` 以及 `OAuthConfigInit`/
`OAuthGitLabInit` 命令负责初始化与维护。

- **oauth_wind / oauth_github / oauth_google / oauth_gitlab / ...**
  - 每个键对应一个 JSON 对象，典型字段：
  - `enabled`：是否启用该提供方。
  - `name` / `icon` / `color`：后台展示使用的名称、图标类和主题色。
  - `base_url`：OAuth 服务端基础 URL（GitLab 等自建服务需要）。
  - `client_id` / `client_secret`：客户端凭据。
  - `scopes`：授权范围数组。
  - `authorize_path` / `token_path` / `userinfo_path` / `revoke_path`：各端点路径（部分提供方可为空）。
  - `user_id_field` / `username_field` / `email_field` / `nickname_field` / `avatar_field`：用户信息字段映射。

---

## 14. 邮件系统配置

> 由 `MailController` 和 `MailWorker` 使用，支持多发信平台与轮询策略。

- **mail_providers**
  - JSON 数组，每个元素为一个发信平台配置：`{id,name,type|dsn,host,port,username,password,encryption,weight,enabled,...}`。
- **mail_strategy**
  - 平台选择策略：
  - `weighted`：按权重轮询（默认）。
  - `rr`：简单轮询。

此外，邮件队列相关的 `rabbitmq_mail_*` 键见第 7 节 MQ 配置。

---

## 15. 会话 / Session 配置

> 后台 Session 配置界面通过单个 JSON 配置键管理 Web 会话行为。

- **session_config**
  - JSON 对象，典型字段：
  - `handler`：Session 处理器类名，如 `FileSessionHandler`、`RedisSessionHandler` 等。
  - `type`：存储类型，`file` / `redis` / `redis_cluster`。
  - `session_name`：Cookie 名称。
  - `auto_update_timestamp`：是否在访问时自动更新过期时间。
  - `lifetime` / `cookie_lifetime`：服务端/客户端 Session 生命周期（秒）。
  - `cookie_path` / `domain` / `http_only` / `secure` / `same_site`：Cookie 行为控制。
  - `file_save_path`：文件会话存储路径。
  - `redis_*`：Redis 相关连接配置（如 host/port/auth/database/prefix 等）。

---

## 16. 系统版本与内部维护配置

- **system_app_version**
  - 当前应用版本号，由 `VersionAutoTasks` 和 `SystemPostUpdateCommand` 在系统更新后写入，用于触发一次性维护任务（菜单导入、Twig
    缓存清理、应用缓存清理等）。

---

> 后续如果新增配置项或通过 `blog_config()` 读取新的键，请在本文件增加对应条目，并在相关控制器/服务的注释中保持描述一致。
