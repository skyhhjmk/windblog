<?php

namespace app\oauth;

use Exception;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 微信开放平台 OAuth 2.0 Provider
 * 用于网站应用微信登录
 *
 * @see https://developers.weixin.qq.com/doc/oplatform/Website_App/WeChat_Login/Wechat_Login.html
 */
class WeChatOAuthProvider extends AbstractProvider
{
    use BearerAuthorizationTrait;

    /**
     * 微信开放平台授权基础 URL
     */
    public const BASE_URL = 'https://open.weixin.qq.com';

    /**
     * 微信 API 基础 URL
     */
    public const API_URL = 'https://api.weixin.qq.com';

    /**
     * 构造函数
     *
     * @param array $options
     * @param array $collaborators
     */
    public function __construct(array $options = [], array $collaborators = [])
    {
        parent::__construct($options, $collaborators);
    }

    /**
     * 获取授权 URL
     *
     * 第一步：请求CODE
     * https://open.weixin.qq.com/connect/qrconnect?appid=APPID&redirect_uri=REDIRECT_URI&response_type=code&scope=SCOPE&state=STATE#wechat_redirect
     *
     * @return string
     */
    public function getBaseAuthorizationUrl(): string
    {
        return self::BASE_URL . '/connect/qrconnect';
    }

    /**
     * 获取访问令牌 URL
     *
     * 第二步：通过code获取access_token
     * https://api.weixin.qq.com/sns/oauth2/access_token?appid=APPID&secret=SECRET&code=CODE&grant_type=authorization_code
     *
     * @param array $params
     *
     * @return string
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return self::API_URL . '/sns/oauth2/access_token';
    }

    /**
     * 获取用户信息 URL
     *
     * 第三步：获取用户个人信息（UnionID机制）
     * https://api.weixin.qq.com/sns/userinfo?access_token=ACCESS_TOKEN&openid=OPENID
     *
     * @param AccessToken $token
     *
     * @return string
     */
    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        $openid = $token->getValues()['openid'] ?? '';

        return self::API_URL . '/sns/userinfo?access_token=' . $token->getToken() . '&openid=' . $openid;
    }

    /**
     * 获取默认权限范围
     *
     * 网站应用目前仅填写 snsapi_login 即可
     *
     * @return array
     */
    protected function getDefaultScopes(): array
    {
        return ['snsapi_login'];
    }

    /**
     * 获取授权 URL 的查询参数
     *
     * 微信使用 appid 而不是 client_id
     *
     * @param array $options
     *
     * @return array
     */
    protected function getAuthorizationParameters(array $options): array
    {
        $params = parent::getAuthorizationParameters($options);

        // 微信使用 appid，需要重命名参数
        if (isset($params['client_id'])) {
            $params['appid'] = $params['client_id'];
            unset($params['client_id']);
        }

        // 微信需要在URL中添加 #wechat_redirect 锚点
        return $params;
    }

    /**
     * 获取访问令牌请求的参数
     *
     * 微信获取 access_token 时使用 appid 和 secret，而不是 client_id 和 client_secret
     *
     * @param array $params
     *
     * @return array
     */
    protected function getAccessTokenOptions(array $params): array
    {
        $options = parent::getAccessTokenOptions($params);

        return [
            'appid' => $this->clientId,
            'secret' => $this->clientSecret,
            'code' => $params['code'],
            'grant_type' => 'authorization_code',
        ];
    }

    /**
     * 获取访问令牌请求
     *
     * 微信使用 GET 方法获取 access_token
     *
     * @param array $params
     *
     * @return RequestInterface
     */
    protected function getAccessTokenRequest(array $params)
    {
        $method = $this->getAccessTokenMethod();
        $url = $this->getAccessTokenUrl($params);
        $options = $this->getAccessTokenOptions($params);

        // 微信使用 GET 请求，参数放在 URL 中
        $url .= '?' . http_build_query($options);

        return $this->getRequest($method, $url, []);
    }

    /**
     * 获取访问令牌的 HTTP 方法
     *
     * 微信使用 GET 方法
     *
     * @return string
     */
    protected function getAccessTokenMethod(): string
    {
        return self::METHOD_GET;
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
                $data['errmsg'] ?? $response->getReasonPhrase(),
                $response->getStatusCode(),
                $response
            );
        }

        // 微信返回的错误格式: {"errcode":40029,"errmsg":"invalid code"}
        if (isset($data['errcode']) && $data['errcode'] != 0) {
            throw new IdentityProviderException(
                $data['errmsg'] ?? 'Unknown error',
                $data['errcode'],
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
     * @return WeChatOAuthResourceOwner
     */
    protected function createResourceOwner(array $response, AccessToken $token): WeChatOAuthResourceOwner
    {
        return new WeChatOAuthResourceOwner($response);
    }

    /**
     * 刷新访问令牌
     *
     * 微信支持刷新 access_token
     * https://api.weixin.qq.com/sns/oauth2/refresh_token?appid=APPID&grant_type=refresh_token&refresh_token=REFRESH_TOKEN
     *
     * @param string $refreshToken
     *
     * @return AccessToken|null
     */
    public function refreshAccessToken(string $refreshToken): ?AccessToken
    {
        try {
            $url = self::API_URL . '/sns/oauth2/refresh_token';
            $params = [
                'appid' => $this->clientId,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ];

            $url .= '?' . http_build_query($params);
            $request = $this->getRequest('GET', $url, []);
            $response = $this->getResponse($request);
            $data = $this->parseResponse($response);

            return $this->createAccessToken($data, null);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 检验授权凭证（access_token）是否有效
     *
     * @param string $accessToken
     * @param string $openid
     *
     * @return bool
     */
    public function checkAccessToken(string $accessToken, string $openid): bool
    {
        try {
            $url = self::API_URL . '/sns/auth';
            $params = [
                'access_token' => $accessToken,
                'openid' => $openid,
            ];

            $url .= '?' . http_build_query($params);
            $request = $this->getRequest('GET', $url, []);
            $response = $this->getResponse($request);
            $data = $this->parseResponse($response);

            return isset($data['errcode']) && $data['errcode'] == 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
