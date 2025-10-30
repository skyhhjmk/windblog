<?php

namespace app\oauth;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

/**
 * 微信 OAuth 资源所有者（用户信息）
 *
 * 微信返回的用户信息格式：
 * {
 *   "openid": "OPENID",
 *   "nickname": "NICKNAME",
 *   "sex": 1,
 *   "province": "PROVINCE",
 *   "city": "CITY",
 *   "country": "COUNTRY",
 *   "headimgurl": "http://wx.qlogo.cn/mmopen/...",
 *   "privilege": ["PRIVILEGE1", "PRIVILEGE2"],
 *   "unionid": "UNIONID"
 * }
 */
class WeChatOAuthResourceOwner implements ResourceOwnerInterface
{
    /**
     * 原始响应数据
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
     * 获取用户ID（openid）
     *
     * openid 是用户在当前应用的唯一标识
     * unionid 是用户在开放平台账号下的唯一标识（如果有的话）
     *
     * @return string|null
     */
    public function getId(): ?string
    {
        // 优先使用 unionid，如果没有则使用 openid
        return $this->response['unionid'] ?? $this->response['openid'] ?? null;
    }

    /**
     * 获取 OpenID
     *
     * @return string|null
     */
    public function getOpenId(): ?string
    {
        return $this->response['openid'] ?? null;
    }

    /**
     * 获取 UnionID
     *
     * @return string|null
     */
    public function getUnionId(): ?string
    {
        return $this->response['unionid'] ?? null;
    }

    /**
     * 获取昵称
     *
     * @return string|null
     */
    public function getNickname(): ?string
    {
        return $this->response['nickname'] ?? null;
    }

    /**
     * 获取头像 URL
     *
     * @return string|null
     */
    public function getAvatarUrl(): ?string
    {
        return $this->response['headimgurl'] ?? null;
    }

    /**
     * 获取性别
     *
     * 1 为男性，2 为女性，0 为未知
     *
     * @return int
     */
    public function getSex(): int
    {
        return $this->response['sex'] ?? 0;
    }

    /**
     * 获取省份
     *
     * @return string|null
     */
    public function getProvince(): ?string
    {
        return $this->response['province'] ?? null;
    }

    /**
     * 获取城市
     *
     * @return string|null
     */
    public function getCity(): ?string
    {
        return $this->response['city'] ?? null;
    }

    /**
     * 获取国家
     *
     * @return string|null
     */
    public function getCountry(): ?string
    {
        return $this->response['country'] ?? null;
    }

    /**
     * 获取特权信息
     *
     * @return array
     */
    public function getPrivilege(): array
    {
        return $this->response['privilege'] ?? [];
    }

    /**
     * 获取原始响应数据（实现接口要求）
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->response;
    }

    /**
     * 获取用户名（用于兼容）
     *
     * 微信没有用户名概念，使用昵称代替
     *
     * @return string|null
     */
    public function getUsername(): ?string
    {
        return $this->getNickname();
    }

    /**
     * 获取邮箱（用于兼容）
     *
     * 微信不提供邮箱信息
     *
     * @return string|null
     */
    public function getEmail(): ?string
    {
        return null;
    }

    /**
     * 获取显示名称
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->getNickname();
    }
}
