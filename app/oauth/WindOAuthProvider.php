<?php

namespace app\oauth;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;

/**
 * Wind OAuth 2.0 Provider
 * 用于对接 wind_oauth 服务器
 */
class WindOAuthProvider extends AbstractProvider
{
    use BearerAuthorizationTrait;

    /**
     * OAuth 服务器基础 URL
     *
     * @var string
     */
    protected string $baseUrl;

    /**
     * 构造函数
     *
     * @param array $options
     * @param array $collaborators
     */
    public function __construct(array $options = [], array $collaborators = [])
    {
        parent::__construct($options, $collaborators);

        $this->baseUrl = $options['baseUrl'] ?? 'http://localhost:8787';
    }

    /**
     * 获取授权 URL
     *
     * @return string
     */
    public function getBaseAuthorizationUrl(): string
    {
        return $this->baseUrl . '/oauth/authorize';
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
        return $this->baseUrl . '/oauth/token';
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
        return $this->baseUrl . '/oauth/userinfo';
    }

    /**
     * 获取默认权限范围
     *
     * @return array
     */
    protected function getDefaultScopes(): array
    {
        return ['basic', 'profile'];
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
     * @return WindOAuthResourceOwner
     */
    protected function createResourceOwner(array $response, AccessToken $token): WindOAuthResourceOwner
    {
        return new WindOAuthResourceOwner($response);
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
        try {
            $url = $this->baseUrl . '/oauth/revoke';
            $request = $this->getRequest('POST', $url, [
                'body' => http_build_query(['token' => $token]),
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);

            $response = $this->getResponse($request);
            $data = $this->parseResponse($response);

            return isset($data['success']) && $data['success'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 验证令牌（内省）
     *
     * @param string $token
     *
     * @return array|null
     */
    public function introspectToken(string $token): ?array
    {
        try {
            $url = $this->baseUrl . '/oauth/introspect';
            $request = $this->getRequest('POST', $url, [
                'body' => http_build_query(['token' => $token]),
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);

            $response = $this->getResponse($request);
            $data = $this->parseResponse($response);

            if (isset($data['active']) && $data['active']) {
                return $data;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
