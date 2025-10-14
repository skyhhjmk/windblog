# CLAUDE.md

本文件为 Claude Code (claude.ai/code) 在此仓库中工作时提供指导。

## 项目概述

WindBlog 是一个基于 Webman 框架构建的高性能博客系统（PHP 8.2+）。项目通过大量使用缓存、消息队列和静态生成来强调性能。使用 PostgreSQL 作为主数据库，可选支持 Redis（缓存）、ElasticSearch（搜索）和 RabbitMQ（消息队列）。

**注意**：这是一个正在积极开发的项目，存在已知的技术债务和架构问题（参见 README.md 的"NOTE"部分）。代码库包含 AI 生成的代码和人工编写的代码，导致编码模式不一致。

## 常用命令

### 开发与运行

**Linux/Unix:**
```bash
# 前台启动
php start.php start

# 后台启动（守护进程模式）
php start.php start -d

# 停止
php start.php stop

# 重启
php start.php restart

# 平滑重载（不断开连接）
php start.php reload

# 查看状态
php start.php status

# 查看连接
php start.php connections
```

**Windows:**
```bash
# 启动服务器（使用 windows.php 包装器）
php windows.php

# 或使用批处理文件
windows.bat
```

**控制台命令:**
```bash
# 列出所有可用命令
php console list

# 数据库配置命令
php console config:database
php console config:redis

# 管理员重新初始化
php console config:admin:reinit
```

### 测试与质量检查

```bash
# 运行所有测试
vendor/bin/phpunit

# 运行特定测试
vendor/bin/phpunit tests/Unit/SomeTest.php

# 使用 PHPStan 进行静态分析（级别 6）
vendor/bin/phpstan analyze

# 注意：PHPStan 配置在 phpstan.neon.dist 中
```

### 安装与设置

```bash
# 安装依赖
composer install

# 仅生产环境依赖
composer install --no-dev --optimize-autoloader

# 首次安装步骤
# 1. 复制 .env.example 为 .env 并配置
# 2. 启动应用程序
# 3. 访问 /app/admin 完成安装
# 4. 安装完成后重启应用以初始化 worker 进程
```

### Docker

```bash
# 构建镜像（默认使用清华镜像源）
docker build -t windblog .

# 使用官方源构建
docker build --build-arg MIRROR=official -t windblog .

# 运行容器
docker run -d -p 8787:8787 windblog
```

## 架构与核心概念

### 框架：Webman（基于 WorkerMan）

Webman 是一个基于 WorkerMan 构建的高性能 PHP 框架。与传统 PHP 应用每个请求创建新进程不同，Webman 作为常驻进程运行，使用 worker 池。

**重要影响：**
- 代码更改需要进程重载/重启（使用 `php start.php reload`）
- 静态变量在同一 worker 的请求之间保持
- `Monitor` 进程在开发模式下自动检测文件变化并重载
- 多个 worker 进程处理请求（默认：`cpu_count() * 4`）

### 多进程架构

应用同时运行多种进程类型（在 `config/process.php` 中配置）：

1. **webman** - HTTP 请求处理器（多个 worker）
2. **monitor** - 文件变化检测和自动重载（仅开发环境）
3. **task** - 定时任务执行（cron 任务）
4. **performance** - 性能指标收集（仅 Redis）
5. **http_callback** - HTTP 回调队列处理器（安装后）
6. **link_monitor** - 友链监控（安装后）
7. **importer** - WordPress 导入处理器（安装后）
8. **static_generator** - 静态站点生成（安装后）
9. **mail_worker** - 邮件队列处理器（安装后）

**注意**：进程 5-9 仅在 `.env` 文件存在后（安装后）才注册。

### 数据库架构

**主数据库：PostgreSQL**
- 项目原本是 MySQL，后来迁移到 PostgreSQL
- ORM：Illuminate Database（Laravel 的 Eloquent）
- 模型位于 `app/model/`：Post、Category、Tag、Comment、Link、Media、Author 等
- 多对多关系通过中间表（PostCategory、PostTag、PostAuthor）

### 缓存架构

**服务**：`app/service/CacheService.php`

**多驱动支持**（通过 `CACHE_DRIVER` 环境变量）：
- `redis` - Redis 缓存（生产环境推荐）
- `apcu` - APCu 共享内存缓存
- `memcached` - Memcached
- `memory` - 进程内内存（每个 worker 独立，不共享）
- `none` - 禁用缓存

**高级特性**：
- 缓存驱动失败时自动降级（非严格模式）
- 通过分布式锁防止缓存击穿
- 负缓存用于不存在的数据（可配置 TTL）
- 抖动以防止缓存雪崩
- 键前缀支持（`CACHE_PREFIX`）
- 提供 PSR-16 适配器

**关键行为**：`blog_config_*` 键跳过负缓存以防止启动失败。

### 模板系统

**服务**：`app/service/TwigTemplateService.php`

**主题支持**：
- 实现了 `Webman\View` 接口
- 主题目录由 `blog_config('theme', 'default')` 决定
- 模板解析：`view/{theme}/{template}.html.twig` → `view/{template}.html.twig`
- Twig 扩展位于 `app/view/extension/`
- 插件钩子：`template.vars_filter`、`template.html_filter`、`template.render_start`、`template.render_end`

### CSRF 保护

**服务**：`app/service/CSRFService.php`
**中间件**：`app/middleware/CSRFMiddleware.php`
**注解**：`app\annotation\CSRFVerify`

**特性**：
- 基于注解的控制器方法保护
- 一次性令牌（单次使用）
- 限时令牌（可配置过期时间）
- 值绑定（例如用户 ID）
- 多种令牌来源：POST、请求头（X-CSRF-TOKEN）、GET、cookies
- 模板助手：`app/service/CSRFHelper.php`

### 插件系统

**服务**：`app/service/PluginService.php`

**WordPress 风格的钩子系统**：
- 动作：`add_action()`、`do_action()`、`remove_action()`
- 过滤器：`add_filter()`、`apply_filters()`、`remove_filter()`
- 插件目录：`app/wind_plugins/`
- 每个插件有一个 `plugin.php` 清单文件
- 插件状态存储在 `blog_config('plugins.enabled')` 中
- 示例插件：`app/wind_plugins/sample/plugin.php`

**可用钩子**（完整列表见代码）：
- 模板：`template.vars_filter`、`template.html_filter`、`template.render_start`、`template.render_end`
- 应用中还有许多其他钩子

### 消息队列（RabbitMQ）

**服务**：`app/service/MQService.php`

**队列消费者**：
- `MailWorker` - 邮件发送队列
- `HttpCallback` - HTTP 回调队列
- `LinkMonitor` - 友链监控队列
- `StaticGenerator` - 静态缓存生成队列

**模式**：生产者推送到队列 → Worker 进程异步消费

### ElasticSearch 集成

**服务**：
- `app/service/ElasticService.php` - 核心 ES 操作
- `app/service/ElasticSyncService.php` - 文章同步
- `app/service/ElasticRebuildService.php` - 索引重建

**用途**：搜索优化（可选，优雅降级到数据库搜索）

### 静态站点生成

**进程**：`app/process/StaticGenerator.php`

为文章和归档生成静态 HTML 页面以减少数据库负载。存储在 `public/static/`（或配置的路径）。

### 核心中间件

- `app/middleware/AuthCheck.php` - 管理员认证
- `app/middleware/CSRFMiddleware.php` - CSRF 保护
- `app/middleware/DebugToolkit.php` - 调试工具栏（仅开发环境）
- `app/middleware/IpChecker.php` - IP 白名单/黑名单
- `app/middleware/Lang.php` - 国际化
- `app/middleware/PluginSupport.php` - 插件系统集成
- `app/middleware/StaticCacheRedirect.php` - 静态缓存服务
- `app/middleware/StaticFile.php` - 静态文件处理

### 辅助函数

**全局函数**位于 `app/functions.php`：

- `cache($key, $value, $set, $ttl)` - 缓存操作
- `blog_config($key, $default, $init, $use_cache, $set)` - 博客配置（存储在数据库）
- `get_cache_handler()` - 获取缓存驱动实例
- 许多其他实用函数

### 路由结构

**文件**：`config/route.php`

路由使用 Webman 的 Route 门面定义，**默认路由已禁用**。所有路由必须显式定义。

**URL 模式**：
- 文章：`/post/{keyword}` 或 `/post/{keyword}.html`
- 分类：`/category/{slug}` 或 `/c/{slug}`
- 标签：`/tag/{slug}` 或 `/t/{slug}`
- 搜索：`/search` 带分页
- 友链：`/link`
- 管理后台：`/app/admin`（通过 webman/admin 插件）

## 重要配置文件

- `.env` - 环境配置（从 `.env.example` 复制）
- `config/app.php` - 应用程序设置
- `config/database.php` - 数据库连接
- `config/process.php` - Worker 进程配置
- `config/route.php` - 路由定义
- `config/view.php` - Twig 模板配置
- `config/middleware.php` - 全局中间件栈
- `phpunit.xml` - PHPUnit 配置
- `phpstan.neon.dist` - PHPStan 静态分析配置

## 开发工作流程

1. **文件更改**：在开发模式下，Monitor 进程会自动检测文件变化并重载
2. **代码重载**：使用 `php start.php reload` 进行平滑重载而不断开连接
3. **硬重启**：当 reload 不够时使用 `php start.php restart`
4. **清除缓存**：使用 `CacheService::clearCache($pattern)` 或通过管理面板清除
5. **静态重新生成**：通过管理面板触发或 `StaticGenerator` 进程

## WordPress 导入

**服务**：
- `app/service/WordpressImporter.php`
- `app/process/ImportProcess.php`

支持从 WordPress XML 导出导入文章、分类、标签和媒体。

## 性能考虑

- **缓存至关重要**：大多数视图和数据查询都大量使用缓存
- **静态生成**：文章可以预渲染为静态 HTML
- **数据库查询**：高效使用 Eloquent ORM；注意 N+1 查询问题
- **Worker 数量**：默认为 `cpu_count() * 4`；可在 `config/process.php` 中调整
- **OPcache/JIT**：Docker 中默认启用生产设置

## 已知问题与特性

根据 README.md，此项目有：
- 不一致的编码风格（AI + 人类协作）
- 安全漏洞（谨慎使用）
- 违反 PSR 标准
- 不完整的管理设置（许多需要直接编辑数据库）
- 从原始 MySQL 版本迁移到 PostgreSQL 的 bug

**贡献时**：优先考虑功能而非完美的代码风格。项目优先考虑可工作的特性。

## 额外资源

- Webman 文档：https://www.workerman.net/doc/webman/
- 项目问题：https://github.com/skyhhjmk/windblog/issues
- 许可证：MIT
