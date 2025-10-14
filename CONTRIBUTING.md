# Contributing to WindBlog

首先，感谢您考虑为 WindBlog 做出贡献！正是像您这样的人使 WindBlog 成为一个出色的工具。

## 行为准则

参与本项目即表示您同意遵守我们的行为准则。请友善待人，尊重他人的观点和经验。

## 如何贡献

### 报告错误

在报告错误之前，请确保该错误尚未被报告。如果您发现了一个新的错误，请：

1. 使用清晰且描述性的标题
2. 详细描述重现步骤
3. 提供具体的示例
4. 描述您期望看到的行为
5. 包含屏幕截图（如果适用）
6. 提供您的环境信息（PHP 版本、操作系统等）

### 建议新功能

我们欢迎功能建议！在提出新功能建议时：

1. 使用清晰且描述性的标题
2. 详细描述建议的功能
3. 解释为什么这个功能对项目有用
4. 如果可能，提供实现建议

### 提交代码

#### 开发流程

1. **Fork 项目**
   ```bash
   git clone https://github.com/your-username/windblog.git
   cd windblog
   ```

2. **创建分支**
   ```bash
   git checkout -b feature/your-feature-name
   # 或
   git checkout -b fix/your-bug-fix
   ```

3. **设置开发环境**
   ```bash
   composer install
   cp .env.example .env
   # 配置 .env 文件
   php start.php start
   ```

4. **进行更改**
   - 遵循现有的代码风格
   - 为新功能编写测试
   - 确保所有测试通过
   - 更新文档（如果需要）

5. **运行测试**
   ```bash
   # 运行单元测试
   vendor/bin/phpunit

   # 运行静态分析
   vendor/bin/phpstan analyze

   # 运行代码格式检查
   vendor/bin/php-cs-fixer fix --dry-run --diff
   ```

6. **提交更改**
   ```bash
   git add .
   git commit -m "类型: 简短描述

   详细描述更改内容..."
   ```

   提交消息格式：
   - `feat:` 新功能
   - `fix:` 错误修复
   - `docs:` 文档更新
   - `style:` 代码格式（不影响代码运行）
   - `refactor:` 重构（既不是新功能也不是错误修复）
   - `test:` 添加测试
   - `chore:` 构建过程或辅助工具的变动

7. **推送到 GitHub**
   ```bash
   git push origin feature/your-feature-name
   ```

8. **创建 Pull Request**
   - 前往 GitHub 上的原始仓库
   - 点击 "New Pull Request"
   - 选择您的分支
   - 填写 PR 模板

#### Pull Request 指南

**好的 PR 应该：**

- 解决一个问题或实现一个功能
- 包含相关测试
- 更新相关文档
- 遵循项目的代码风格
- 提供清晰的提交消息
- 链接到相关的 issue

**PR 模板：**

```markdown
## 描述
简要描述此 PR 的目的

## 更改类型
- [ ] 错误修复
- [ ] 新功能
- [ ] 重大更改
- [ ] 文档更新

## 相关 Issue
修复 #(issue 编号)

## 测试
描述您如何测试了这些更改

## 截图（如适用）
添加屏幕截图

## 检查清单
- [ ] 代码遵循项目的代码风格
- [ ] 我已进行自我审查
- [ ] 我已添加了必要的注释
- [ ] 我已更新了文档
- [ ] 我的更改没有产生新的警告
- [ ] 我已添加了测试
- [ ] 所有测试都通过
```

### 代码风格

我们使用 PHP-CS-Fixer 来维护一致的代码风格：

```bash
# 自动修复代码风格问题
vendor/bin/php-cs-fixer fix

# 检查而不修复
vendor/bin/php-cs-fixer fix --dry-run --diff
```

**关键规范：**

- 使用 PSR-12 代码风格
- 使用 4 个空格缩进（不使用制表符）
- 类文件必须只声明一个类
- 方法名使用 camelCase
- 类名使用 PascalCase
- 常量使用 UPPER_CASE
- 为公共方法添加 PHPDoc 注释
- 保持行长度在 120 个字符以内

### 测试

- 为新功能编写测试
- 确保测试覆盖边界情况
- 测试应该是独立的
- 使用描述性的测试名称

```php
public function testUserCanCreatePost(): void
{
    // 测试实现
}
```

### 文档

- 更新 README.md（如果添加新功能）
- 更新 CLAUDE.md（如果改变架构）
- 为公共 API 添加 PHPDoc 注释
- 在代码中添加有意义的注释

## 开发技巧

### 调试

```php
// 使用 var_dump 和 Webman 的调试功能
var_dump($variable);

// 日志记录
Log::info('Debug message', ['context' => $data]);
```

### 性能测试

```bash
# 使用 ab 进行简单的性能测试
ab -n 1000 -c 10 http://localhost:8787/
```

### 数据库查询

```php
// 使用查询日志
DB::enableQueryLog();
// ... 执行查询
$queries = DB::getQueryLog();
```

## 获取帮助

如果您需要帮助：

1. 查看 [文档](https://github.com/skyhhjmk/windblog/tree/main/doc)
2. 搜索现有的 [Issues](https://github.com/skyhhjmk/windblog/issues)
3. 在 Issues 中提问
4. 联系维护者：admin@biliwind.com

## 许可

通过贡献，您同意您的贡献将在 MIT 许可下授权。

## 致谢

感谢所有为 WindBlog 做出贡献的人！

---

再次感谢您的贡献！💖
