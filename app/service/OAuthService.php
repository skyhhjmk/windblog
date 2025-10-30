<?php

namespace app\service;

use app\oauth\GenericOAuthProvider;
use app\oauth\WeChatOAuthProvider;
use app\oauth\WindOAuthProvider;
use Exception;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Token\AccessToken;
use support\Log;

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
        // 内置平台处理
        $builtInProviders = [
            'wind' => fn () => $this->createWindProvider($config),
            'github' => fn () => $this->createGithubProvider($config),
            'google' => fn () => $this->createGoogleProvider($config),
            'wechat' => fn () => $this->createWeChatProvider($config),
        ];

        if (isset($builtInProviders[$provider])) {
            return $builtInProviders[$provider]();
        }

        // 通用 OAuth Provider（适用于自定义平台）
        if (!empty($config['base_url'])) {
            return $this->createGenericProvider($config);
        }

        return null;
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
     * 创建微信 OAuth Provider
     *
     * @param array $config
     *
     * @return WeChatOAuthProvider
     */
    protected function createWeChatProvider(array $config): WeChatOAuthProvider
    {
        return new WeChatOAuthProvider([
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => $config['redirect_uri'],
        ]);
    }

    /**
     * 创建通用 OAuth Provider
     *
     * @param array $config
     *
     * @return GenericOAuthProvider
     */
    protected function createGenericProvider(array $config): GenericOAuthProvider
    {
        $options = [
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => $config['redirect_uri'],
            'baseUrl' => $config['base_url'],
        ];

        // 可选端点配置
        if (!empty($config['authorize_path'])) {
            $options['authorizePath'] = $config['authorize_path'];
        }
        if (!empty($config['token_path'])) {
            $options['tokenPath'] = $config['token_path'];
        }
        if (!empty($config['userinfo_path'])) {
            $options['userInfoPath'] = $config['userinfo_path'];
        }
        if (!empty($config['revoke_path'])) {
            $options['revokePath'] = $config['revoke_path'];
        }

        // 字段映射配置
        if (!empty($config['user_id_field'])) {
            $options['userIdField'] = $config['user_id_field'];
        }
        if (!empty($config['username_field'])) {
            $options['usernameField'] = $config['username_field'];
        }
        if (!empty($config['email_field'])) {
            $options['emailField'] = $config['email_field'];
        }
        if (!empty($config['nickname_field'])) {
            $options['nicknameField'] = $config['nickname_field'];
        }
        if (!empty($config['avatar_field'])) {
            $options['avatarField'] = $config['avatar_field'];
        }

        // 默认权限范围
        if (!empty($config['scopes']) && is_array($config['scopes'])) {
            $options['defaultScopes'] = $config['scopes'];
        }

        return new GenericOAuthProvider($options);
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
        } catch (Exception $e) {
            Log::error('OAuth getUserData failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * 标准化用户数据
     *
     * @param string                                  $provider
     * @param array                                   $userData
     * @param AccessToken $accessToken
     *
     * @return array
     */
    protected function normalizeUserData(string $provider, array $userData, $accessToken): array
    {
        // 内置平台的特殊处理
        $specialMapping = [
            'wind' => [
                'id' => (string) ($userData['user_id'] ?? $userData['id'] ?? ''),
                'username' => $userData['username'] ?? '',
                'email' => $userData['email'] ?? null,
                'name' => $userData['name'] ?? $userData['nickname'] ?? null,
                'avatar' => $userData['avatar'] ?? null,
            ],
            'github' => [
                'id' => (string) ($userData['id'] ?? ''),
                'username' => $userData['login'] ?? '',
                'email' => $userData['email'] ?? null,
                'name' => $userData['name'] ?? null,
                'avatar' => $userData['avatar_url'] ?? null,
            ],
            'google' => [
                'id' => (string) ($userData['sub'] ?? $userData['id'] ?? ''),
                'username' => $userData['email'] ?? '',
                'email' => $userData['email'] ?? null,
                'name' => $userData['name'] ?? null,
                'avatar' => $userData['picture'] ?? null,
            ],
            'wechat' => [
                'id' => (string) ($userData['unionid'] ?? $userData['openid'] ?? ''),
                'username' => $userData['nickname'] ?? 'wechat_user',
                'email' => null, // 微信不提供邮箱
                'name' => $userData['nickname'] ?? null,
                'avatar' => $userData['headimgurl'] ?? null,
            ],
        ];

        // 使用特殊映射或默认映射
        $mapped = $specialMapping[$provider] ?? [
            'id' => (string) ($userData['id'] ?? ''),
            'username' => $userData['username'] ?? '',
            'email' => $userData['email'] ?? null,
            'name' => $userData['name'] ?? $userData['nickname'] ?? null,
            'avatar' => $userData['avatar'] ?? null,
        ];

        // 添加通用字段
        return array_merge($mapped, [
            'access_token' => $accessToken->getToken(),
            'refresh_token' => $accessToken->getRefreshToken(),
            'expires_at' => $accessToken->getExpires() ? date('Y-m-d H:i:s', $accessToken->getExpires()) : null,
            'extra' => $userData,
        ]);
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
        } catch (Exception $e) {
            Log::error('OAuth getAuthorizationUrl failed: ' . $e->getMessage());

            return null;
        }
    }
}
