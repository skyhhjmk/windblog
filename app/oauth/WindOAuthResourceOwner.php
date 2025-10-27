<?php

namespace app\oauth;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

/**
 * Wind OAuth 资源所有者（用户信息）
 */
class WindOAuthResourceOwner implements ResourceOwnerInterface
{
    /**
     * 原始用户数据
     *
     * @var array
     */
    protected array $response;

    /**
     * 构造函数
     *
     * @param array $response
     */
    public function __construct(array $response)
    {
        $this->response = $response;
    }

    /**
     * 获取用户 ID
     *
     * @return string|int
     */
    public function getId()
    {
        return $this->response['user_id'] ?? null;
    }

    /**
     * 获取用户名
     *
     * @return string|null
     */
    public function getUsername(): ?string
    {
        return $this->response['username'] ?? null;
    }

    /**
     * 获取用户邮箱
     *
     * @return string|null
     */
    public function getEmail(): ?string
    {
        return $this->response['email'] ?? null;
    }

    /**
     * 获取用户昵称/姓名
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->response['name'] ?? $this->response['nickname'] ?? null;
    }

    /**
     * 获取权限范围
     *
     * @return array
     */
    public function getScopes(): array
    {
        return $this->response['scope'] ?? [];
    }

    /**
     * 获取原始响应数据
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->response;
    }
}
