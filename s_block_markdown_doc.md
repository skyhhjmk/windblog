# S-Block Markdown 扩展语法功能文档

> 目标：在 **纯 Markdown 写作体验** 下，提供类似 **WordPress 区块编辑器** 的结构化与可视化能力，同时保持 **语法极简、易记、输入成本低
**。

---

## 1. 设计目标与原则

### 1.1 核心目标

- 在 Markdown 模式下实现「区块化编辑」
- 支持常见内容块（提示、卡片、按钮、布局、折叠等）
- 语法短、直观、可快速手写
- 不破坏原生 Markdown 解析
- 适合博客、文档、知识库、技术写作

### 1.2 设计原则

| 原则             | 说明                   |
|----------------|----------------------|
| 极简             | 单一前缀、无冗余符号           |
| 可读             | 原文即结构，非“代码感”         |
| 可扩展            | 新 Block 不影响旧语法       |
| 可降级            | 不识别时仍是可读文本           |
| Markdown First | Block 内完全支持 Markdown |

---

## 2. 总体语法规范

### 2.1 Block 基本结构

```
::block-type [param=value] [flag]
Markdown 内容
::end
```

### 2.2 核心规则

- 所有 Block 以 `::` 开头
- Block 以 `::end` 结束
- Block 内支持完整 Markdown
- 单行 Block 可省略 `::end`
- 未识别的 block-type 应忽略并降级为普通文本

---

## 3. Block 类型总览

| Block | 用途            | WordPress 对应  |
|-------|---------------|---------------|
| hero  | 页面头部 / Banner | Cover         |
| info  | 信息提示          | Info / Notice |
| warn  | 警告提示          | Warning       |
| tip   | 建议提示          | Tip           |
| card  | 卡片容器          | Card          |
| btn   | 按钮            | Button        |
| grid  | 多列布局          | Columns       |
| media | 媒体嵌入          | Embed         |
| fold  | 折叠内容          | Toggle        |
| tabs  | 标签页           | Tabs          |
| step  | 步骤流程          | Steps         |

---

## 4. 详细 Block 定义

### 4.1 Hero（头部区块）

```
::hero dark align=center
# 产品发布
让你的 Markdown 更强大
::btn [立即开始](#)
::end
```

参数说明：

| 参数           | 可选值                   | 说明   |
|--------------|-----------------------|------|
| dark / light | flag                  | 主题模式 |
| align        | left / center / right | 内容对齐 |

---

### 4.2 Info / Warn / Tip（提示类区块）

```
::info
这是一个信息提示
::end
```

```
::warn
这是一个警告
::end
```

```
::tip
这是一个建议
::end
```

特性：

- 自动图标
- 颜色由类型决定

---

### 4.3 Card（卡片）

```
::card
### 项目名称
简要描述内容
::end
```

可嵌套：

- btn
- media
- list

---

### 4.4 Button（按钮，单行 Block）

```
::btn [下载](https://example.com)
```

扩展参数：

```
::btn primary [开始使用](#)
```

| Flag    | 说明   |
|---------|------|
| primary | 主按钮  |
| ghost   | 幽灵按钮 |
| small   | 小尺寸  |

---

### 4.5 Grid（多列布局）

```
::grid 2
- 左侧内容
- 右侧内容
::end
```

规则：

- 数字表示列数
- 子项使用列表语法

---

### 4.6 Media（媒体嵌入）

```
::media https://youtu.be/xxxx
```

支持：

- YouTube
- Bilibili
- Vimeo
- 图片 / 视频直链

---

### 4.7 Fold（折叠内容）

```
::fold title="常见问题"
这里是折叠内容
::end
```

参数：

| 参数    | 必选 | 说明   |
|-------|----|------|
| title | 是  | 折叠标题 |

---

### 4.8 Tabs（标签页）

```
::tabs
::tab 标题一
内容一
::tab 标题二
内容二
::end
```

说明：

- tab 为子 block，仅在 tabs 内有效

---

### 4.9 Step（步骤流程）

```
::step
1. 安装依赖
2. 编写内容
3. 发布
::end
```

---

## 5. 嵌套规则

允许嵌套：

```
::card
::info
卡片内的提示
::end
::btn [查看](#)
::end
```

禁止嵌套：

- grid 内再嵌套 grid（避免复杂度）

---

## 6. 错误与降级策略

| 情况       | 行为             |
|----------|----------------|
| 缺失 ::end | 自动补全到文档末尾      |
| 未知 block | 原样渲染为 Markdown |
| 参数错误     | 忽略参数           |

---

## 7. PHP CommonMark + Vditor 实现方案（重点）

本章节针对 **PHP 后端使用 league/commonmark**，前端编辑器使用 **Vditor（Markdown 编辑 + 预览）** 的实际工程场景进行优化说明。

---

### 7.1 总体架构设计

```
Vditor (编辑 / 即时预览)
   │
   │  S-Block Markdown
   ▼
Vditor 自定义 Markdown 扩展（JS）
   │
   │  HTML（预览）
   ▼
后端 PHP（league/commonmark 扩展）
   │
   ▼
最终 HTML / 存储 / SSR
```

目标：

- **前端预览与后端渲染结果一致**
- S-Block 语法在前后端拥有一致解析规则

---

### 7.2 后端：PHP CommonMark 扩展要求

#### 7.2.1 技术选型

- 使用 `league/commonmark` v2+
- 通过 **自定义 BlockParser + BlockRenderer** 实现

#### 7.2.2 解析策略（推荐）

- 使用 `BlockStartParserInterface`
- 识别以 `::` 开头的行
- block-type 为紧随其后的第一个 token
- `::end` 作为显式结束符

#### 7.2.3 Block AST 设计建议

```php
class SBlockNode extends AbstractBlock
{
    public string $type;
    public array $params = [];
}
```

子 Block（如 tab）可作为子节点存储。

#### 7.2.4 后端渲染原则

- Block 内部再次走 CommonMark 正常解析流程
- 输出 HTML 时统一添加 `s-` 前缀 class

```html

<div class="s-block s-info">...</div>
```

---

### 7.3 前端：Vditor 扩展要求（关键）

Vditor **不会自动识别自定义 Block**，必须显式扩展。

#### 7.3.1 Markdown 解析扩展

需要实现：

- `vditor.preview.markdown` 扩展规则
- 使用正则或 tokenizer 识别：

```
::block-type ...
...
::end
```

并将其转换为 HTML 结构（与后端一致）。

---

### 7.3.2 即时预览一致性要求

前端渲染 HTML **必须与 PHP CommonMark 输出结构一致**：

| 项目    | 要求                 |
|-------|--------------------|
| 标签    | div / section 统一   |
| class | s-block + s-{type} |
| 嵌套    | 层级一致               |

这样可保证：

- 编辑器预览
- 前台页面渲染
- SEO / SSR

三者完全一致。

---

### 7.4 Vditor 工具栏（语法提示按钮）规范

#### 7.4.1 设计目标

- 降低记忆成本
- 一键插入 Block 模板
- 保持 Markdown 输入流畅

#### 7.4.2 工具栏按钮分组建议

| 分组  | 按钮                    |
|-----|-----------------------|
| 提示类 | Info / Warn / Tip     |
| 布局类 | Card / Grid / Tabs    |
| 功能类 | Button / Media / Fold |

---

#### 7.4.3 按钮插入模板示例

**Info 按钮：**

```
::info
这里输入提示内容
::end
```

**Grid 按钮（2 列）：**

```
::grid 2
- 列 1 内容
- 列 2 内容
::end
```

**Button 按钮：**

```
::btn [按钮文字](https://)
```

---

### 7.5 语法提示与补全建议

在 Vditor 中实现：

- 输入 `::` 自动提示 block-type
- 输入 `::grid` 自动提示列数
- 输入 `::fold` 自动提示 `title=""`

提示内容应来自 **同一份 Block 定义 JSON**。

---

### 7.6 Block 定义中心化（强烈建议）

维护一份 `sblock.schema.json`：

```json
{
  "info": {
    "end": true
  },
  "btn": {
    "end": false
  },
  "grid": {
    "end": true,
    "args": [
      "columns"
    ]
  }
}
```

用途：

## 8. 输出结构示例（HTML）

```
<div class="s-card">
  <h3>项目名称</h3>
  <p>简要描述内容</p>
</div>
```

---

## 9. 版本与扩展约定

- Block 名称全部小写
- 新 Block 不影响旧解析
- 允许自定义 block 前缀（如 `@@`）

---

## 10. 适用场景

- 博客系统
- 静态站点生成器（Hugo / VitePress）
- 知识库
- 文档系统
- Headless CMS

---

## 11. 附：最小示例

```
::hero
# 欢迎
::btn [开始](#)
::end

::grid 2
- 内容 A
- 内容 B
::end
```

---

**S-Block 的定位：**
> *“写 Markdown，却像在用区块编辑器。”*

