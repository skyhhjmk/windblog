<?php

namespace app\oauth;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

/**
 * 通用 OAuth 资源所有者（用户信息）
 */
class GenericOAuthResourceOwner implements ResourceOwnerInterface
{
    /**
     * 原始响应数据
     *
     * @var array
     */
    protected array $response;

    /**
     * 字段映射配置
     *
     * @var array
     */
    protected array $fieldMapping;

    /**
     * 构造函数
     *
     * @param array $response     原始响应数据
     * @param array $fieldMapping 字段映射配置
     */
    public function __construct(array $response, array $fieldMapping = [])
    {
        $this->response = $response;
        $this->fieldMapping = array_merge([
            'userIdField' => 'id',
            'usernameField' => 'username',
            'emailField' => 'email',
            'nicknameField' => 'nickname',
            'avatarField' => 'avatar',
        ], $fieldMapping);
    }

    /**
     * 获取用户 ID
     *
     * @return string|int|null
     */
    public function getId()
    {
        return $this->getField($this->fieldMapping['userIdField']);
    }

    /**
     * 获取用户名
     *
     * @return string|null
     */
    public function getUsername(): ?string
    {
        return $this->getField($this->fieldMapping['usernameField']);
    }

    /**
     * 获取邮箱
     *
     * @return string|null
     */
    public function getEmail(): ?string
    {
        return $this->getField($this->fieldMapping['emailField']);
    }

    /**
     * 获取昵称
     *
     * @return string|null
     */
    public function getNickname(): ?string
    {
        return $this->getField($this->fieldMapping['nicknameField']);
    }

    /**
     * 获取头像
     *
     * @return string|null
     */
    public function getAvatar(): ?string
    {
        return $this->getField($this->fieldMapping['avatarField']);
    }

    /**
     * 获取原始响应数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->response;
    }

    /**
     * 根据字段名获取值（支持嵌套字段，如 'user.name'）
     *
     * @param string $field
     *
     * @return mixed
     */
    protected function getField(string $field)
    {
        // 支持嵌套字段访问，如 'user.name'
        if (strpos($field, '.') !== false) {
            $parts = explode('.', $field);
            $value = $this->response;

            foreach ($parts as $part) {
                if (!isset($value[$part])) {
                    return null;
                }
                $value = $value[$part];
            }

            return $value;
        }

        return $this->response[$field] ?? null;
    }
}
