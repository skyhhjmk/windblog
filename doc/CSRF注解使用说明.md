# CSRFVerify 注解使用说明

## 概述

本系统提供了一个基于注解的CSRF验证机制，通过在控制器方法上添加 `#[CSRFVerify]` 注解来自动进行CSRF token验证。

## 安装配置

系统已自动配置完成，包含以下文件：

1. **注解类**: `app/annotation/CSRFVerify.php`
2. **中间件**: `app/middleware/CSRFMiddleware.php`
3. **配置**: 已在 `config/middleware.php` 中注册

## 使用方法

### 基本用法

```php
use app\annotation\CSRFVerify;
use support\Request;
use support\Response;

class YourController
{
    #[CSRFVerify]
    public function submitForm(Request $request): Response
    {
        // 你的业务逻辑
        return response('操作成功');
    }
}
```

### 自定义配置

```php
#[CSRFVerify(
    tokenName: 'custom_token',     // 自定义token字段名
    message: '自定义错误消息',      // 自定义错误消息
    methods: ['POST', 'DELETE'],   // 指定需要验证的HTTP方法
    expire: 600,                   // token过期时间（秒）
    oneTime: true,                 // 是否使用一次性token
    bindToValue: true,             // 是否绑定到特定值
    bindField: 'user_id'           // 绑定值的字段名
)]
public function yourMethod(Request $request): Response
{
    // 你的业务逻辑
}
```

### 类级别注解

```php
#[CSRFVerify] // 类中所有方法都会进行CSRF验证
class YourController
{
    public function method1(Request $request): Response { /* ... */ }
    public function method2(Request $request): Response { /* ... */ }
}
```

## Token 传递方式

### 1. 表单字段 (推荐)
```html
<form method="POST">
    <input type="hidden" name="_token" value="{{ session()->get('_token') }}">
    <!-- 其他表单字段 -->
    <button type="submit">提交</button>
</form>
```

### 2. 请求头
```javascript
// JavaScript示例
fetch('/your-route', {
    method: 'POST',
    headers: {
        'X-CSRF-TOKEN': '你的token值'
    }
});
```

### 3. 查询参数 (不推荐，仅作兼容)
```
POST /your-route?_token=你的token值
```

## 生成CSRF Token

### 使用CSRFHelper辅助类（推荐）

```php
use app\service\CSRFHelper;

// 生成token隐藏字段（直接在视图中使用）
echo CSRFHelper::generateField($request);

// 生成token值
$token = CSRFHelper::generateValue($request);

// 生成一次性token
$oneTimeToken = CSRFHelper::oneTimeToken($request, '_token', 300);

// 生成用户绑定token  
$userToken = CSRFHelper::userBoundToken($request, '_token', 'user_id', 3600);

// 生成一次性用户绑定token
$secureToken = CSRFHelper::oneTimeUserBoundToken($request, '_token', 'user_id', 300);
```

### 使用CSRFService服务类

```php
use app\service\CSRFService;

// 创建服务实例
$csrfService = new CSRFService();

// 配置选项
$csrfService
    ->setTokenExpire(600)
    ->setOneTimeToken(true)
    ->setBindToValue(true, 'user_id');

// 生成token
$token = $csrfService->generateToken($request);

// 在视图中使用
<input type="hidden" name="_token" value="{{ $token }}">
```

### 直接使用session
```html
<input type="hidden" name="_token" value="{{ session()->get('_token.value') }}">
```

## 安全选项说明

### 1. 一次性Token (`oneTime: true`)
- 验证成功后自动从session中删除
- 防止重复提交攻击
- 适用于敏感操作（如支付、重要设置修改）

### 2. 值绑定 (`bindToValue: true`)
- 将token绑定到特定值（如用户ID）
- 防止token在不同用户间共享
- 需要设置 `bindField` 指定绑定字段

### 3. 过期时间 (`expire: 秒数`)
- 设置token的有效期
- 0表示永不过期（不推荐）
- 默认3600秒（1小时）

### 4. 自定义绑定字段 (`bindField: '字段名'`)
- 指定绑定值的session字段名
- 默认：'user_id'
- 可以根据业务需求自定义

## 高级用法示例

### 一次性用户绑定token
```php
#[CSRFVerify(
    oneTime: true,
    bindToValue: true,
    bindField: 'user_id',
    expire: 300,
    message: '安全token验证失败'
)]
public function sensitiveOperation(Request $request): Response
{
    // 敏感操作逻辑
}
```

## 错误处理

当CSRF验证失败时，系统会返回：
- **HTTP 403状态码**
- **JSON响应** (如果请求头包含 `Accept: application/json`)
- **纯文本错误消息**

## 示例控制器

参考 `app/controller/ExampleController.php` 查看完整示例。

## 注意事项

1. 默认验证的HTTP方法: POST, PUT, PATCH, DELETE
2. 默认token字段名: `_token`
3. 验证使用安全的哈希比较函数 `hash_equals()`
4. 中间件会优先使用方法级别注解，如果没有则使用类级别注解

## 调试技巧

如果遇到验证问题，可以：
1. 检查session中是否有token
2. 确认token传递方式正确
3. 查看浏览器开发者工具的网络请求