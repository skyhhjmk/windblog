<?php

namespace app\service;

use Exception;
use support\Request;

/**
 * CSRF辅助工具类
 *
 * 提供便捷的CSRF token生成和验证方法
 */
class CSRFHelper
{
    /**
     * 生成CSRF token并设置Cookie
     * 适用于静态缓存场景
     *
     * @param Request $request   请求对象
     * @param string  $tokenName token名称
     * @param array   $options   生成选项
     *
     * @return void
     * @throws Exception
     */
    public static function setTokenCookie(Request $request, string $tokenName = '_token', array $options = []): void
    {
        $options['set_cookie'] = true; // 确保设置Cookie

        $csrfService = new CSRFService();
        $csrfService->generateToken($request, $tokenName, $options);
    }

    /**
     * 生成CSRF token值
     *
     * @param Request $request   请求对象
     * @param string  $tokenName token名称
     * @param array   $options   生成选项
     *
     * @return string token值
     * @throws Exception
     */
    public static function generateValue(Request $request, string $tokenName = '_token', array $options = []): string
    {
        $csrfService = new CSRFService();

        return $csrfService->generateToken($request, $tokenName, $options);
    }

    /**
     * 验证CSRF token
     *
     * @param Request $request   请求对象
     * @param string  $tokenName token名称
     * @param array   $options   验证选项
     *
     * @return bool
     * @throws Exception
     */
    public static function validate(Request $request, string $tokenName = '_token', array $options = []): bool
    {
        $csrfService = new CSRFService();

        return $csrfService->validateToken($request, $tokenName, $options);
    }

    /**
     * 创建一次性token
     *
     * @param Request $request   请求对象
     * @param string  $tokenName token名称
     * @param int     $expire    过期时间（秒）
     *
     * @return string
     * @throws Exception
     */
    public static function oneTimeToken(Request $request, string $tokenName = '_token', int $expire = 3600): string
    {
        return self::generateValue($request, $tokenName, [
            'one_time' => true,
            'expire' => $expire,
        ]);
    }

    /**
     * 创建用户绑定token
     *
     * @param Request $request   请求对象
     * @param string  $tokenName token名称
     * @param string  $bindField 绑定字段名
     * @param int     $expire    过期时间（秒）
     *
     * @return string
     * @throws Exception
     */
    public static function userBoundToken(Request $request, string $tokenName = '_token', string $bindField = 'user_id', int $expire = 3600): string
    {
        return self::generateValue($request, $tokenName, [
            'bind_value' => $request->session()->get($bindField),
            'expire' => $expire,
        ]);
    }

    /**
     * 创建一次性用户绑定token
     *
     * @param Request $request   请求对象
     * @param string  $tokenName token名称
     * @param string  $bindField 绑定字段名
     * @param int     $expire    过期时间（秒）
     *
     * @return string
     * @throws Exception
     */
    public static function oneTimeUserBoundToken(Request $request, string $tokenName = '_token', string $bindField = 'user_id', int $expire = 300): string
    {
        return self::generateValue($request, $tokenName, [
            'one_time' => true,
            'bind_value' => $request->session()->get($bindField),
            'expire' => $expire,
        ]);
    }
}
