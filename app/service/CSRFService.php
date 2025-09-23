<?php

namespace app\service;

use Exception;
use support\Request;
use Webman\Http\Response;

/**
 * CSRF验证服务
 * 
 * 提供CSRF token生成、验证和相关安全功能
 */
class CSRFService
{
    /**
     * @var string 默认token名称
     */
    protected string $defaultTokenName = '_token';
    
    /**
     * @var int token过期时间（秒）
     */
    protected int $tokenExpire = 3600;
    
    /**
     * @var bool 是否使用一次性token
     */
    protected bool $useOneTimeToken = false;
    
    /**
     * @var bool 是否绑定到特定值（如用户ID）
     */
    protected bool $bindToValue = false;
    
    /**
     * @var string 绑定值的字段名
     */
    protected string $bindField = 'user_id';

    /**
     * 生成CSRF token
     *
     * @param Request     $request   请求对象
     * @param string|null $tokenName token名称
     * @param array       $options   生成选项
     *
     * @return string
     * @throws Exception
     */
    public function generateToken(Request $request, ?string $tokenName = null, array $options = []): string
    {
        $tokenName = $tokenName ?: $this->defaultTokenName;
        $options = array_merge([
            'expire' => $this->tokenExpire,
            'one_time' => $this->useOneTimeToken,
            'bind_value' => $this->bindToValue ? $this->getBindValue($request) : null
        ], $options);
        
        // 生成安全的随机token
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $token = bin2hex(md5(time() . $e->getCode()));
        }

        // 存储token信息到session
        $tokenData = [
            'value' => $token,
            'expire' => time() + $options['expire'],
            'one_time' => $options['one_time'],
            'bind_value' => $options['bind_value']
        ];
        
        $request->session()->set($tokenName, $tokenData);
        
        return $token;
    }

    /**
     * 验证CSRF token
     *
     * @param Request     $request   请求对象
     * @param string|null $tokenName token名称
     * @param array       $options   验证选项
     *
     * @return bool
     * @throws Exception
     */
    public function validateToken(Request $request, string $tokenName = null, array $options = []): bool
    {
        $tokenName = $tokenName ?: $this->defaultTokenName;
        
        // 获取session中的token数据
        $tokenData = $request->session()->get($tokenName);
        if (!$tokenData || !is_array($tokenData)) {
            return false;
        }
        
        // 检查token是否过期
        if (isset($tokenData['expire']) && time() > $tokenData['expire']) {
            $request->session()->delete($tokenName);
            return false;
        }
        
        // 检查绑定值是否匹配
        if ($this->bindToValue && isset($tokenData['bind_value'])) {
            $currentBindValue = $this->getBindValue($request);
            if ($tokenData['bind_value'] !== $currentBindValue) {
                return false;
            }
        }
        
        // 从请求中获取token
        $requestToken = $this->getTokenFromRequest($request, $tokenName);
        if (!$requestToken) {
            return false;
        }
        
        // 验证token值
        if (!hash_equals($tokenData['value'], $requestToken)) {
            return false;
        }
        
        // 如果是一次性token，验证后删除
        if ($tokenData['one_time'] ?? false) {
            $request->session()->delete($tokenName);
        }
        
        return true;
    }
    
    /**
     * 从请求中获取token
     * 
     * @param Request $request 请求对象
     * @param string $tokenName token名称
     * @return string|null
     */
    public function getTokenFromRequest(Request $request, string $tokenName): ?string
    {
        // 优先从POST数据获取
        $token = $request->post($tokenName);
        if ($token) {
            return $token;
        }
        
        // 从请求头获取
        $token = $request->header('X-CSRF-TOKEN');
        if ($token) {
            return $token;
        }
        
        // 从查询参数获取
        $token = $request->get($tokenName);
        if ($token) {
            return $token;
        }
        
        return null;
    }

    /**
     * 获取绑定值
     *
     * @param Request $request 请求对象
     *
     * @return mixed
     * @throws Exception
     */
    protected function getBindValue(Request $request)
    {
        // 默认绑定到用户ID，可以根据需要扩展
        return $request->session()->get($this->bindField);
    }
    
    /**
     * 设置token过期时间
     * 
     * @param int $seconds 过期时间（秒）
     * @return $this
     */
    public function setTokenExpire(int $seconds): self
    {
        $this->tokenExpire = $seconds;
        return $this;
    }
    
    /**
     * 设置是否使用一次性token
     * 
     * @param bool $oneTime 是否一次性
     * @return $this
     */
    public function setOneTimeToken(bool $oneTime): self
    {
        $this->useOneTimeToken = $oneTime;
        return $this;
    }
    
    /**
     * 设置是否绑定到特定值
     * 
     * @param bool $bind 是否绑定
     * @param string $field 绑定字段名
     * @return $this
     */
    public function setBindToValue(bool $bind, string $field = 'user_id'): self
    {
        $this->bindToValue = $bind;
        $this->bindField = $field;
        return $this;
    }

    /**
     * 获取token信息（用于调试）
     *
     * @param Request     $request   请求对象
     * @param string|null $tokenName token名称
     *
     * @return array|null
     * @throws Exception
     */
    public function getTokenInfo(Request $request, string $tokenName = null): ?array
    {
        $tokenName = $tokenName ?: $this->defaultTokenName;
        return $request->session()->get($tokenName);
    }
}