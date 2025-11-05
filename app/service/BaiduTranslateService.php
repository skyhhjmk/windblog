<?php

declare(strict_types=1);

namespace app\service;

use support\Log;
use Throwable;

/**
 * 百度翻译服务
 */
class BaiduTranslateService
{
    /**
     * 百度翻译API地址
     */
    private const API_URL = 'https://fanyi-api.baidu.com/api/trans/vip/translate';

    /**
     * 测试百度翻译配置是否可用
     *
     * @param string $appId     APP ID
     * @param string $secretKey 密钥
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConfig(string $appId, string $secretKey): array
    {
        $testText = '测试';
        $result = $this->translateToEnglish($testText, $appId, $secretKey);

        if ($result !== null) {
            return [
                'success' => true,
                'message' => '配置测试成功，翻译结果: ' . $result,
            ];
        }

        return [
            'success' => false,
            'message' => '配置测试失败，请检查APP ID和密钥是否正确',
        ];
    }

    /**
     * 翻译文本到英文
     *
     * @param string      $text      需要翻译的文本
     * @param string|null $appId     百度翻译APP ID（可选，不传则从配置获取）
     * @param string|null $secretKey 百度翻译密钥（可选，不传则从配置获取）
     *
     * @return string|null 翻译后的英文文本，失败返回null
     */
    public function translateToEnglish(string $text, ?string $appId = null, ?string $secretKey = null): ?string
    {
        try {
            // 从配置中获取百度翻译配置
            if ($appId === null || $secretKey === null) {
                $config = $this->getBaiduConfig();
                $appId ??= $config['app_id'] ?? '';
                $secretKey ??= $config['secret_key'] ?? '';
            }

            // 验证配置
            if (empty($appId) || empty($secretKey)) {
                Log::warning('Baidu translate config not found');

                return null;
            }

            // 自动检测源语言
            $from = 'auto';
            $to = 'en';

            // 生成随机数
            $salt = (string) rand(10000, 99999);

            // 生成签名
            $sign = $this->generateSign($appId, $text, $salt, $secretKey);

            // 构建请求参数
            $params = [
                'q' => $text,
                'from' => $from,
                'to' => $to,
                'appid' => $appId,
                'salt' => $salt,
                'sign' => $sign,
            ];

            // 发送请求
            $url = self::API_URL . '?' . http_build_query($params);
            $response = $this->httpGet($url);

            if ($response === false) {
                Log::error('Baidu translate API request failed');

                return null;
            }

            // 解析响应
            $result = json_decode($response, true);

            if (!isset($result['trans_result']) || !is_array($result['trans_result'])) {
                Log::error('Baidu translate API response invalid: ' . $response);

                return null;
            }

            // 返回第一个翻译结果
            if (isset($result['trans_result'][0]['dst'])) {
                return $result['trans_result'][0]['dst'];
            }

            return null;
        } catch (Throwable $e) {
            Log::error('Baidu translate error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * 获取百度翻译配置
     *
     * @return array
     */
    private function getBaiduConfig(): array
    {
        try {
            $appId = blog_config('baidu_translate_appid', '', true);
            $secretKey = blog_config('baidu_translate_secret', '', true);

            return [
                'app_id' => $appId,
                'secret_key' => $secretKey,
            ];
        } catch (Throwable $e) {
            Log::error('Failed to get baidu translate config: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * 生成签名
     *
     * @param string $appId     APP ID
     * @param string $query     需要翻译的文本
     * @param string $salt      随机数
     * @param string $secretKey 密钥
     *
     * @return string
     */
    private function generateSign(string $appId, string $query, string $salt, string $secretKey): string
    {
        // 拼接字符串：appid+query+salt+密钥
        $str = $appId . $query . $salt . $secretKey;

        // 返回MD5值
        return md5($str);
    }

    /**
     * 发送HTTP GET请求
     *
     * @param string $url 请求地址
     *
     * @return string|false
     */
    private function httpGet(string $url)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                Log::error('HTTP request error: ' . $error);

                return false;
            }

            return $response;
        } catch (Throwable $e) {
            Log::error('HTTP request exception: ' . $e->getMessage());

            return false;
        }
    }
}
