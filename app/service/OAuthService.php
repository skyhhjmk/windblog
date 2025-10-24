<?php

namespace app\service;

use app\oauth\WindOAuthProvider;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\Google;

/**
 * OAuth 服务类
 * 统一管理不同的 OAuth 提供商
 */
class OAuthService
{
    /**
     * 创建 OAuth Provider
     *
     * @param string $provider 提供商名称
     * @param array  $config   配置信息
     *
     * @return AbstractProvider|null
     */
    public function createProvider(string $provider, array $config): ?AbstractProvider
    {
        return match ($provider) {
            'wind' => $this->createWindProvider($config),
            'github' => $this->createGithubProvider($config),
            'google' => $this->createGoogleProvider($config),
            default => null,
        };
    }

    /**
     * 创建 Wind OAuth Provider
     *
     * @param array $config
     *
     * @return WindOAuthProvider
     */
    protected function createWindProvider(array $config): WindOAuthProvider
    {
        return new WindOAuthProvider([
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => $config['redirect_uri'],
            'baseUrl' => $config['base_url'] ?? 'http://localhost:8787',
        ]);
    }

    /**
     * 创建 GitHub OAuth Provider
     *
     * @param array $config
     *
     * @return Github
     */
    protected function createGithubProvider(array $config): Github
    {
        return new Github([
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => $config['redirect_uri'],
        ]);
    }

    /**
     * 创建 Google OAuth Provider
     *
     * @param array $config
     *
     * @return Google
     */
    protected function createGoogleProvider(array $config): Google
    {
        return new Google([
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => $config['redirect_uri'],
        ]);
    }

    /**
     * 获取用户信息
     *
     * @param string $provider 提供商名称
     * @param string $code     授权码
     * @param array  $config   配置信息
     *
     * @return array|null
     */
    public function getUserData(string $provider, string $code, array $config): ?array
    {
        try {
            $oauthProvider = $this->createProvider($provider, $config);
            if (!$oauthProvider) {
                return null;
            }

            // 使用授权码交换访问令牌
            $accessToken = $oauthProvider->getAccessToken('authorization_code', [
                'code' => $code,
            ]);

            // 获取用户信息
            $resourceOwner = $oauthProvider->getResourceOwner($accessToken);
            $userData = $resourceOwner->toArray();

            // 统一返回格式
            return $this->normalizeUserData($provider, $userData, $accessToken);
        } catch (\Exception $e) {
            \support\Log::error('OAuth getUserData failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * 标准化用户数据
     *
     * @param string                                  $provider
     * @param array                                   $userData
     * @param \League\OAuth2\Client\Token\AccessToken $accessToken
     *
     * @return array
     */
    protected function normalizeUserData(string $provider, array $userData, $accessToken): array
    {
        return match ($provider) {
            'wind' => [
                'id' => (string) ($userData['user_id'] ?? ''),
                'username' => $userData['username'] ?? '',
                'email' => $userData['email'] ?? null,
                'name' => $userData['name'] ?? $userData['nickname'] ?? null,
                'avatar' => $userData['avatar'] ?? null,
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires() ? date('Y-m-d H:i:s', $accessToken->getExpires()) : null,
                'extra' => $userData,
            ],
            'github' => [
                'id' => (string) ($userData['id'] ?? ''),
                'username' => $userData['login'] ?? '',
                'email' => $userData['email'] ?? null,
                'name' => $userData['name'] ?? null,
                'avatar' => $userData['avatar_url'] ?? null,
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires() ? date('Y-m-d H:i:s', $accessToken->getExpires()) : null,
                'extra' => $userData,
            ],
            'google' => [
                'id' => (string) ($userData['sub'] ?? $userData['id'] ?? ''),
                'username' => $userData['email'] ?? '',
                'email' => $userData['email'] ?? null,
                'name' => $userData['name'] ?? null,
                'avatar' => $userData['picture'] ?? null,
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires() ? date('Y-m-d H:i:s', $accessToken->getExpires()) : null,
                'extra' => $userData,
            ],
            default => [
                'id' => (string) ($userData['id'] ?? ''),
                'username' => $userData['username'] ?? '',
                'email' => $userData['email'] ?? null,
                'name' => $userData['name'] ?? null,
                'avatar' => $userData['avatar'] ?? null,
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires() ? date('Y-m-d H:i:s', $accessToken->getExpires()) : null,
                'extra' => $userData,
            ],
        };
    }

    /**
     * 获取授权 URL
     *
     * @param string $provider
     * @param array  $config
     * @param string $state
     * @param array  $scopes
     *
     * @return string|null
     */
    public function getAuthorizationUrl(string $provider, array $config, string $state, array $scopes = []): ?string
    {
        try {
            $oauthProvider = $this->createProvider($provider, $config);
            if (!$oauthProvider) {
                return null;
            }

            $options = ['state' => $state];

            if (!empty($scopes)) {
                $options['scope'] = $scopes;
            }

            return $oauthProvider->getAuthorizationUrl($options);
        } catch (\Exception $e) {
            \support\Log::error('OAuth getAuthorizationUrl failed: ' . $e->getMessage());

            return null;
        }
    }
}
