<?php

namespace app\oauth;

use Exception;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;

/**
 * 通用 OAuth 2.0 Provider
 * 用于对接任意标准 OAuth 2.0 服务器
 */
class GenericOAuthProvider extends AbstractProvider
{
    use BearerAuthorizationTrait;

    /**
     * OAuth 服务器基础 URL
     *
     * @var string
     */
    protected string $baseUrl;

    /**
     * 授权端点路径
     *
     * @var string
     */
    protected string $authorizePath = '/oauth/authorize';

    /**
     * 令牌端点路径
     *
     * @var string
     */
    protected string $tokenPath = '/oauth/token';

    /**
     * 用户信息端点路径
     *
     * @var string
     */
    protected string $userInfoPath = '/oauth/userinfo';

    /**
     * 撤销令牌端点路径
     *
     * @var string|null
     */
    protected ?string $revokePath = '/oauth/revoke';

    /**
     * 默认权限范围
     *
     * @var array
     */
    protected array $defaultScopes = [];

    /**
     * 用户ID字段映射
     *
     * @var string
     */
    protected string $userIdField = 'id';

    /**
     * 用户名字段映射
     *
     * @var string
     */
    protected string $usernameField = 'username';

    /**
     * 邮箱字段映射
     *
     * @var string
     */
    protected string $emailField = 'email';

    /**
     * 昵称字段映射
     *
     * @var string
     */
    protected string $nicknameField = 'nickname';

    /**
     * 头像字段映射
     *
     * @var string
     */
    protected string $avatarField = 'avatar';

    /**
     * 构造函数
     *
     * @param array $options
     * @param array $collaborators
     */
    public function __construct(array $options = [], array $collaborators = [])
    {
        parent::__construct($options, $collaborators);

        // 必需参数
        $this->baseUrl = $options['baseUrl'] ?? '';

        // 可选端点配置
        if (isset($options['authorizePath'])) {
            $this->authorizePath = $options['authorizePath'];
        }
        if (isset($options['tokenPath'])) {
            $this->tokenPath = $options['tokenPath'];
        }
        if (isset($options['userInfoPath'])) {
            $this->userInfoPath = $options['userInfoPath'];
        }
        if (isset($options['revokePath'])) {
            $this->revokePath = $options['revokePath'];
        }

        // 可选字段映射
        if (isset($options['userIdField'])) {
            $this->userIdField = $options['userIdField'];
        }
        if (isset($options['usernameField'])) {
            $this->usernameField = $options['usernameField'];
        }
        if (isset($options['emailField'])) {
            $this->emailField = $options['emailField'];
        }
        if (isset($options['nicknameField'])) {
            $this->nicknameField = $options['nicknameField'];
        }
        if (isset($options['avatarField'])) {
            $this->avatarField = $options['avatarField'];
        }

        // 默认权限范围
        if (isset($options['defaultScopes']) && is_array($options['defaultScopes'])) {
            $this->defaultScopes = $options['defaultScopes'];
        }
    }

    /**
     * 获取授权 URL
     *
     * @return string
     */
    public function getBaseAuthorizationUrl(): string
    {
        return rtrim($this->baseUrl, '/') . $this->authorizePath;
    }

    /**
     * 获取访问令牌 URL
     *
     * @param array $params
     *
     * @return string
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return rtrim($this->baseUrl, '/') . $this->tokenPath;
    }

    /**
     * 获取用户信息 URL
     *
     * @param AccessToken $token
     *
     * @return string
     */
    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return rtrim($this->baseUrl, '/') . $this->userInfoPath;
    }

    /**
     * 获取默认权限范围
     *
     * @return array
     */
    protected function getDefaultScopes(): array
    {
        return $this->defaultScopes;
    }

    /**
     * 检查响应错误
     *
     * @param ResponseInterface $response
     * @param array|string      $data
     *
     * @throws IdentityProviderException
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if ($response->getStatusCode() >= 400) {
            throw new IdentityProviderException(
                $data['error'] ?? $data['message'] ?? $response->getReasonPhrase(),
                $response->getStatusCode(),
                $response
            );
        }

        if (isset($data['error'])) {
            throw new IdentityProviderException(
                $data['error_description'] ?? $data['error'],
                0,
                $response
            );
        }
    }

    /**
     * 从响应创建资源所有者
     *
     * @param array       $response
     * @param AccessToken $token
     *
     * @return GenericOAuthResourceOwner
     */
    protected function createResourceOwner(array $response, AccessToken $token): GenericOAuthResourceOwner
    {
        return new GenericOAuthResourceOwner($response, [
            'userIdField' => $this->userIdField,
            'usernameField' => $this->usernameField,
            'emailField' => $this->emailField,
            'nicknameField' => $this->nicknameField,
            'avatarField' => $this->avatarField,
        ]);
    }

    /**
     * 撤销令牌
     *
     * @param string $token
     *
     * @return bool
     */
    public function revokeToken(string $token): bool
    {
        if (!$this->revokePath) {
            return false;
        }

        try {
            $url = rtrim($this->baseUrl, '/') . $this->revokePath;
            $request = $this->getRequest('POST', $url, [
                'body' => http_build_query(['token' => $token]),
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);

            $response = $this->getResponse($request);
            $data = $this->parseResponse($response);

            return isset($data['success']) && $data['success'];
        } catch (Exception $e) {
            return false;
        }
    }
}
