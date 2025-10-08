# 可用 Action 与权限列表

说明：Action 为事件式钩子，插件可监听执行。权限采用分域前缀，未授权一律拒绝实际动作逻辑（插件回调内通过 PluginService::ensurePermission 验证）。

域前缀建议：
- request, response, content, post, page, tag, category, comment, media, search, elastic, mail, mq, template, widget, sidebar, pagination, url, i18n, admin, system, cache, import, webhook

约定：
- 权限项形如：{domain}:action.{name}
- 支持通配授权：{domain}:action.*（请谨慎使用）

## 请求/响应

- request_enter
  - 场景：请求进入 Pipeline
  - 参数：Request $request
  - 权限：request:action.enter
- response_exit
  - 场景：响应发出前
  - 参数：Response $response
  - 权限：response:action.exit

## 内容领域

- content.render_start
  - 场景：开始渲染内容（页面/文章）
  - 参数：array $context
  - 权限：content:action.render_start
- content.render_end
  - 场景：完成渲染内容
  - 参数：array $context, Response $response
  - 权限：content:action.render_end

## 文章 Post

- post.created
  - 场景：文章创建完成
  - 参数：Post $post
  - 权限：post:action.created
- post.updated
  - 场景：文章更新完成
  - 参数：Post $post
  - 权限：post:action.updated
- post.deleted
  - 场景：文章删除完成
  - 参数：Post $post
  - 权限：post:action.deleted
- post.published
  - 场景：文章发布上线
  - 参数：Post $post
  - 权限：post:action.published
- post.unpublished
  - 场景：文章下线
  - 参数：Post $post
  - 权限：post:action.unpublished

## 页面 Page

- page.created / page.updated / page.deleted
  - 参数：Page $page
  - 权限：page:action.created / updated / deleted

## 标签/分类

- tag.created / tag.updated / tag.deleted
  - 参数：Tag $tag
  - 权限：tag:action.created / updated / deleted
- category.created / category.updated / category.deleted
  - 参数：Category $category
  - 权限：category:action.created / updated / deleted

## 评论 Comment

- comment.created
  - 场景：新评论创建
  - 参数：Comment $comment
  - 权限：comment:action.created
- comment.moderated
  - 场景：评论审核结果
  - 参数：Comment $comment, string $status
  - 权限：comment:action.moderated
- comment.deleted
  - 参数：Comment $comment
  - 权限：comment:action.deleted

## 媒体 Media

- media.uploaded
  - 场景：媒体上传成功
  - 参数：Media $media
  - 权限：media:action.uploaded
- media.deleted
  - 参数：Media $media
  - 权限：media:action.deleted

## 搜索 / Elastic

- search.query_start / search.query_end
  - 场景：搜索开始/结束
  - 参数：array $query, array $result?
  - 权限：search:action.query_start / query_end
- elastic.rebuild_start / elastic.rebuild_end
  - 场景：重建索引开始/结束
  - 参数：array $stats
  - 权限：elastic:action.rebuild_start / rebuild_end
- elastic.sync_start / elastic.sync_end
  - 场景：数据同步开始/结束
  - 参数：array $stats
  - 权限：elastic:action.sync_start / sync_end

## 邮件 Mail

- mail.before_send
  - 场景：准备发送邮件
  - 参数：array $message
  - 权限：mail:action.before_send
- mail.after_send
  - 场景：发送完成
  - 参数：array $result
  - 权限：mail:action.after_send

## 队列 MQ

- mq.message_published
  - 场景：消息发布到队列
  - 参数：string $topic, array $payload
  - 权限：mq:action.message_published
- mq.message_consumed
  - 场景：消息被消费
  - 参数：string $topic, array $payload
  - 权限：mq:action.message_consumed

## 模板 / Twig / 组件

- template.render_start / template.render_end
  - 场景：Twig 渲染开始/结束
  - 参数：string $template, array $vars
  - 权限：template:action.render_start / render_end
- widget.rendered
  - 场景：组件渲染完成
  - 参数：string $widget, array $context
  - 权限：widget:action.rendered
- sidebar.built
  - 场景：侧边栏组装完成
  - 参数：array $items
  - 权限：sidebar:action.built

## 分页 / URL

- pagination.built
  - 场景：分页数据生成完成
  - 参数：array $pagination
  - 权限：pagination:action.built
- url.generated
  - 场景：URL 生成完成
  - 参数：string $url, array $params
  - 权限：url:action.generated

## I18N / 语言

- i18n.locale_changed
  - 场景：切换语言
  - 参数：string $locale
  - 权限：i18n:action.locale_changed

## 管理 / 用户

- admin.login_success / admin.login_failed
  - 参数：string $username, string $ip
  - 权限：admin:action.login_success / login_failed
- admin.config_saved
  - 场景：后台配置保存
  - 参数：array $changed
  - 权限：admin:action.config_saved

## 系统 / 缓存 / 导入 / Webhook

- cache.cleared
  - 场景：清理系统缓存
  - 参数：string $scope
  - 权限：cache:action.cleared
- import.wordpress_finished
  - 场景：Wordpress 导入完成
  - 参数：array $stats
  - 权限：import:action.wordpress_finished
- webhook.before_call / webhook.after_call
  - 场景：调用外部回调前/后
  - 参数：string $endpoint, array $payload, array $result?
  - 权限：webhook:action.before_call / after_call

> 说明：上述钩子需在相应控制器/服务的关键路径中调用 PluginService::do_action() 接入；插件端在回调内调用 PluginService::ensurePermission(slug, '{domain}:action.{name}') 决定执行。