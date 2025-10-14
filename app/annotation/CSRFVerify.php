<?php

namespace app\annotation;

use Attribute;

/**
 * CSRF验证注解
 *
 * 用于标记需要进行CSRF验证的控制器方法
 *
 * @example
 * #[CSRFVerify]
 * #[CSRFVerify(tokenName: '_token', if_failed_config: 'CSRF token验证失败')]
 * #[CSRFVerify(methods: ['POST', 'PUT', 'DELETE'])]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class CSRFVerify
{
    /**
     * CSRF验证注解构造函数
     *
     * @param string $tokenName        CSRF token字段名称，默认为'_token'
     * @param mixed  $if_failed_config 验证失败时的配置，支持字符串或数组格式
     * @param array  $methods          需要验证的HTTP方法，默认为['POST', 'PUT', 'PATCH', 'DELETE']
     * @param int    $expire           token过期时间（秒），0表示不过期
     * @param bool   $oneTime          是否使用一次性token
     * @param bool   $bindToValue      是否绑定到特定值（如用户ID）
     * @param string $bindField        绑定值的字段名
     * @param mixed  $responseHandler  自定义响应处理器（可调用对象），接收Request和错误消息，返回Response对象
     * @param bool   $jsonResponse     是否总是返回JSON响应
     */
    public function __construct(
        public string $tokenName = '_token',
        public mixed $if_failed_config = 'CSRF token验证失败',
        public array $methods = ['POST', 'PUT', 'PATCH', 'DELETE'],
        public int $expire = 3600,
        public bool $oneTime = false,
        public bool $bindToValue = false,
        public string $bindField = 'user_id',
        public mixed $responseHandler = null,
        public bool $jsonResponse = false
    ) {
    }
}
