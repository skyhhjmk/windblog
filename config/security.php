<?php

/**
 * 安全配置文件
 *
 * 配置各种安全响应头和安全策略
 */

return [
    /*
    |--------------------------------------------------------------------------
    | X-Frame-Options
    |--------------------------------------------------------------------------
    |
    | 控制页面是否可以被嵌入到iframe中
    | 可选值: DENY, SAMEORIGIN, ALLOW-FROM https://example.com
    |
    */
    'x_frame_options' => env('SECURITY_X_FRAME_OPTIONS', 'SAMEORIGIN'),

    /*
    |--------------------------------------------------------------------------
    | Referrer Policy
    |--------------------------------------------------------------------------
    |
    | 控制Referer头的发送策略
    | 可选值:
    | - no-referrer: 不发送
    | - no-referrer-when-downgrade: HTTPS到HTTP不发送
    | - origin: 只发送origin
    | - origin-when-cross-origin: 跨域时只发送origin
    | - same-origin: 同源时才发送
    | - strict-origin: 类似origin，但HTTPS到HTTP不发送
    | - strict-origin-when-cross-origin: 推荐值
    | - unsafe-url: 总是发送完整URL
    |
    */
    'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

    /*
    |--------------------------------------------------------------------------
    | Permissions Policy
    |--------------------------------------------------------------------------
    |
    | 控制浏览器功能和API的使用权限
    | 格式: ['feature' => '(origin1 origin2)', ...]
    | 使用 () 表示禁用该功能
    |
    */
    'permissions_policy' => [
        'camera' => '()',
        'microphone' => '()',
        'geolocation' => '()',
        'payment' => '()',
        'usb' => '()',
        'magnetometer' => '()',
        'accelerometer' => '()',
        'gyroscope' => '()',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy (CSP)
    |--------------------------------------------------------------------------
    |
    | 内容安全策略，防止XSS攻击
    | 设置为null表示不启用CSP（因为CSP配置较复杂，需要根据实际情况调整）
    |
    | 示例配置:
    | [
    |     'default-src' => "'self'",
    |     'script-src' => "'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net",
    |     'style-src' => "'self' 'unsafe-inline' https://fonts.googleapis.com",
    |     'img-src' => "'self' data: https:",
    |     'font-src' => "'self' https://fonts.gstatic.com",
    |     'connect-src' => "'self'",
    |     'frame-ancestors' => "'self'",
    |     'base-uri' => "'self'",
    |     'form-action' => "'self'",
    | ]
    |
    | 注意：CSP配置不当可能导致网站功能异常，建议先在report-only模式下测试
    |
    */
    'content_security_policy' => env('SECURITY_CSP_ENABLED', false) ? [
        'default-src' => "'self'",
        'script-src' => "'self' 'unsafe-inline' 'unsafe-eval'",
        'style-src' => "'self' 'unsafe-inline'",
        'img-src' => "'self' data: https:",
        'font-src' => "'self' data:",
        'connect-src' => "'self'",
        'frame-ancestors' => "'self'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
    ] : null,

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security (HSTS)
    |--------------------------------------------------------------------------
    |
    | 强制浏览器使用HTTPS连接
    | 注意：仅在HTTPS环境下启用，否则可能导致网站无法访问
    |
    */

    // 是否强制启用HSTS（即使不是HTTPS）
    'force_hsts' => env('SECURITY_FORCE_HSTS', false),

    // HSTS有效期（秒）
    'hsts_max_age' => env('SECURITY_HSTS_MAX_AGE', 31536000), // 1年

    // 是否包含子域名
    'hsts_include_subdomains' => env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),

    // 是否加入HSTS预加载列表
    'hsts_preload' => env('SECURITY_HSTS_PRELOAD', false),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | 信任的代理服务器IP列表
    | 用于在反向代理后正确获取客户端真实IP
    |
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', '') ?
        explode(',', env('TRUSTED_PROXIES')) : [],

    /*
    |--------------------------------------------------------------------------
    | IP黑白名单
    |--------------------------------------------------------------------------
    |
    | IP访问控制
    |
    */

    // IP白名单（为空表示不启用）
    'ip_whitelist' => env('IP_WHITELIST', '') ?
        explode(',', env('IP_WHITELIST')) : [],

    // IP黑名单
    'ip_blacklist' => env('IP_BLACKLIST', '') ?
        explode(',', env('IP_BLACKLIST')) : [],

    /*
    |--------------------------------------------------------------------------
    | 文件上传安全
    |--------------------------------------------------------------------------
    */

    // 允许上传的文件扩展名
    'allowed_upload_extensions' => [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
        'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm'],
        'audio' => ['mp3', 'wav', 'ogg', 'flac', 'm4a'],
    ],

    // 最大上传文件大小（字节）
    'max_upload_size' => env('MAX_UPLOAD_SIZE', 20 * 1024 * 1024), // 20MB

    /*
    |--------------------------------------------------------------------------
    | Session安全
    |--------------------------------------------------------------------------
    */

    // Session cookie安全标志
    'session_secure' => env('SESSION_SECURE', false), // 仅HTTPS
    'session_httponly' => env('SESSION_HTTPONLY', true), // 禁止JavaScript访问
    'session_samesite' => env('SESSION_SAMESITE', 'Lax'), // Strict, Lax, None

    /*
    |--------------------------------------------------------------------------
    | CORS配置
    |--------------------------------------------------------------------------
    */

    // 允许的源（为空表示不允许跨域）
    'cors_allowed_origins' => env('CORS_ALLOWED_ORIGINS', '') ?
        explode(',', env('CORS_ALLOWED_ORIGINS')) : [],

    // 允许的HTTP方法
    'cors_allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    // 允许的请求头
    'cors_allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-TOKEN'],

    // 是否允许携带凭证
    'cors_allow_credentials' => env('CORS_ALLOW_CREDENTIALS', false),

    // 预检请求缓存时间（秒）
    'cors_max_age' => env('CORS_MAX_AGE', 86400),
];
