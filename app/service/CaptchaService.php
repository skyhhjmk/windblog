<?php

namespace app\service;

use support\Log;
use support\Request;
use Throwable;

/**
 * 验证码服务
 * 支持 Cloudflare Turnstile 和自建图形验证码
 */
class CaptchaService
{
    /**
     * 验证验证码
     *
     * @param Request $request
     *
     * @return array [是否通过, 错误信息]
     */
    public static function verify(Request $request): array
    {
        try {
            $captchaType = blog_config('captcha_type', 'none', true);
            if ($captchaType === 'none') {
                return [true, ''];
            }

            if ($captchaType === 'image') {
                return self::verifyImageCaptcha($request);
            }

            return [true, ''];
        } catch (Throwable $e) {
            Log::error('[CaptchaService] 验证码验证失败: ' . $e->getMessage());

            return [false, '验证码验证失败'];
        }
    }

    /**
     * 验证图形验证码
     *
     * @param Request $request
     *
     * @return array
     */
    protected static function verifyImageCaptcha(Request $request): array
    {
        $code = $request->post('captcha', '');

        if (empty($code)) {
            return [false, '请输入验证码'];
        }

        $session = $request->session();
        $savedCode = $session->get('captcha_code');
        $expireTime = $session->get('captcha_expire');

        if (empty($savedCode) || empty($expireTime)) {
            return [false, '验证码已过期，请刷新'];
        }

        if (time() > $expireTime) {
            $session->delete('captcha_code');
            $session->delete('captcha_expire');

            return [false, '验证码已过期，请刷新'];
        }

        // 验证码不区分大小写
        if (strtolower($code) !== strtolower($savedCode)) {
            return [false, '验证码错误'];
        }

        // 验证成功后清除验证码
        $session->delete('captcha_code');
        $session->delete('captcha_expire');

        return [true, ''];
    }

    /**
     * 生成图形验证码
     *
     * @param Request $request
     *
     * @return \support\Response
     */
    public static function generateImageCaptcha(Request $request): \support\Response
    {
        try {
            // 生成4位随机验证码
            $code = '';
            $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // 去除易混淆字符
            $length = 4;

            for ($i = 0; $i < $length; $i++) {
                $code .= $characters[random_int(0, strlen($characters) - 1)];
            }

            // 保存到session，5分钟有效
            $session = $request->session();
            $session->set('captcha_code', $code);
            $session->set('captcha_expire', time() + 300);

            // 创建图像
            $width = 120;
            $height = 40;
            $image = imagecreatetruecolor($width, $height);

            if (!$image) {
                throw new \Exception('Failed to create image');
            }

            // 背景色（白色）
            $bgColor = imagecolorallocate($image, 255, 255, 255);
            imagefill($image, 0, 0, $bgColor);

            // 添加干扰线
            for ($i = 0; $i < 3; $i++) {
                $lineColor = imagecolorallocate($image, random_int(150, 200), random_int(150, 200), random_int(150, 200));
                imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $lineColor);
            }

            // 添加干扰点
            for ($i = 0; $i < 100; $i++) {
                $pixelColor = imagecolorallocate($image, random_int(200, 255), random_int(200, 255), random_int(200, 255));
                imagesetpixel($image, random_int(0, $width), random_int(0, $height), $pixelColor);
            }

            // 绘制验证码文字
            for ($i = 0; $i < $length; $i++) {
                $textColor = imagecolorallocate($image, random_int(0, 100), random_int(0, 100), random_int(0, 100));
                $x = 15 + $i * 25;
                $y = random_int(25, 30);
                imagestring($image, 5, $x, $y, $code[$i], $textColor);
            }

            // 输出图像
            ob_start();
            imagepng($image);
            $imageData = ob_get_clean();
            imagedestroy($image);

            return response($imageData, 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        } catch (Throwable $e) {
            Log::error('[CaptchaService] 生成验证码失败: ' . $e->getMessage());

            // 返回一个简单的错误图片
            $image = imagecreatetruecolor(120, 40);
            $bgColor = imagecolorallocate($image, 255, 0, 0);
            imagefill($image, 0, 0, $bgColor);
            ob_start();
            imagepng($image);
            $imageData = ob_get_clean();
            imagedestroy($image);

            return response($imageData, 200, ['Content-Type' => 'image/png']);
        }
    }

    /**
     * 获取前端验证码配置
     *
     * @return array
     */
    public static function getFrontendConfig(): array
    {
        $captchaType = blog_config('captcha_type', 'none', true);
        $config = [
            'type' => $captchaType,
            'enabled' => $captchaType !== 'none',
        ];

        return $config;
    }
}
