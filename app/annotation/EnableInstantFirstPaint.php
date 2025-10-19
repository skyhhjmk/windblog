<?php

namespace app\annotation;

use Attribute;

/**
 * 启用首屏快速渲染注解
 *
 * 用于标记需要启用首屏快速渲染的控制器方法
 * 只有添加了此注解的方法才会触发 InstantFirstPaint 中间件的骨架页逻辑
 *
 * @example
 * #[EnableInstantFirstPaint]
 * public function index(Request $request): Response
 * {
 *     // 控制器方法实现
 * }
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class EnableInstantFirstPaint
{
    /**
     * 构造函数
     *
     * @param bool $enabled 是否启用，默认为true
     */
    public function __construct(
        public bool $enabled = true
    ) {
    }
}
