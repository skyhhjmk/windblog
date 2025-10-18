<?php

namespace app\service;

use support\Request;
use support\Response;

/**
 * 安全服务类
 *
 * 提供全面的安全防护功能，包括输入验证、XSS防护、文件上传安全等
 */
class SecurityService
{
    /**
     * XSS防护规则
     */
    private static array $xssPatterns = [
        // 脚本标签
        '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/si',
        // 内联事件处理器
        '/\bon\w+\s*=\s*["\'][^"\']*["\']/si',
        // javascript: 和 vbscript: 协议
        '/javascript\s*:/si',
        '/vbscript\s*:/si',
        // data: 协议（除图片外）
        '/data\s*:\s*text\/html/si',
        '/data\s*:\s*text\/javascript/si',
        // CSS表达式注入
        '/expression\s*\(/si',
        '/javascript\s*:/si',
        // 危险的HTML属性
        '/<iframe\b[^>]*>/si',
        '/<object\b[^>]*>/si',
        '/<embed\b[^>]*>/si',
    ];

    /**
     * SQL注入防护规则
     */
    private static array $sqlPatterns = [
        '/(\b(union|select|insert|update|delete|drop|create|alter|exec|execute)\b)/i',
        '/(\bor\b\s+\d+\s*=\s*\d+)/i',
        '/(\band\b\s+\d+\s*=\s*\d+)/i',
        '/(\bxp_cmdshell\b)/i',
        '/(\bsp_executesql\b)/i',
        '/(\bsp_oacreate\b)/i',
        '/(\bsp_oadestroy\b)/i',
        '/(\bsp_oamethod\b)/i',
        '/(\bsp_oagetproperty\b)/i',
        '/(\bsp_oasetproperty\b)/i',
        '/(\bsp_addextendedproc\b)/i',
        '/(\bsp_dropextendedproc\b)/i',
        '/(--|#|\/\*|\*\/)/',
        '/(\bload_file\b|\boutfile\b)/i',
        '/(\bmaster\.|information_schema\.|sys\.)/i',
    ];

    /**
     * 文件上传黑名单扩展名
     */
    private static array $dangerousExtensions = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'asp', 'aspx', 'jsp', 'js', 'vb', 'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'dll', 'pl', 'cgi', 'py', 'rb', 'sh',
    ];

    /**
     * 文件上传白名单MIME类型
     */
    private static array $allowedMimeTypes = [
        // 图片类型
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/bmp' => ['bmp'],
        'image/tiff' => ['tiff', 'tif'],
        'image/svg+xml' => ['svg'],
        // 文档类型
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'text/plain' => ['txt'],
        'text/csv' => ['csv'],
        'application/rtf' => ['rtf'],
        // 压缩文件
        'application/zip' => ['zip'],
        'application/x-rar-compressed' => ['rar'],
        'application/x-7z-compressed' => ['7z'],
        'application/gzip' => ['gz'],
        // 音视频
        'audio/mpeg' => ['mp3'],
        'audio/wav' => ['wav'],
        'audio/ogg' => ['ogg'],
        'video/mp4' => ['mp4'],
        'video/avi' => ['avi'],
        'video/mov' => ['mov'],
        'video/wmv' => ['wmv'],
    ];

    /**
     * 深度清理输入数据，防止XSS攻击
     *
     * @param mixed $data 输入数据
     * @param bool $stripTags 是否移除HTML标签
     * @param bool $encodeQuotes 是否编码引号
     * @return mixed 清理后的数据
     */
    public static function sanitizeInput(mixed $data, bool $stripTags = true, bool $encodeQuotes = true): mixed
    {
        if (is_string($data)) {
            // 移除潜在的XSS攻击向量
            $data = preg_replace(self::$xssPatterns, '', $data);

            // 移除HTML标签（如果需要）
            if ($stripTags) {
                $data = strip_tags($data);
            }

            // 转义特殊字符
            $flags = ENT_COMPAT;
            if ($encodeQuotes) {
                $flags |= ENT_QUOTES;
            }

            $data = htmlspecialchars($data, $flags, 'UTF-8');

            // 移除多余的空白字符
            $data = trim($data);

            // 防止双重转义
            $data = stripslashes($data);

        } elseif (is_array($data)) {
            $data = array_map([self::class, 'sanitizeInput'], $data);
        } elseif (is_object($data)) {
            // 对于对象，只处理公共属性
            foreach (get_object_vars($data) as $key => $value) {
                $data->$key = self::sanitizeInput($value);
            }
        }

        return $data;
    }

    /**
     * 验证输入数据是否包含SQL注入特征
     *
     * @param string $input 输入字符串
     * @return bool 是否包含SQL注入特征
     */
    public static function containsSqlInjection(string $input): bool
    {
        foreach (self::$sqlPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 验证文件是否安全
     *
     * @param string $filename 文件名
     * @param string $mimeType MIME类型
     * @param int $fileSize 文件大小（字节）
     * @return array [是否安全, 错误信息]
     */
    public static function validateFileSecurity(string $filename, string $mimeType, int $fileSize): array
    {
        // 检查文件扩展名
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, self::$dangerousExtensions)) {
            return [false, '禁止上传危险文件类型'];
        }

        // 检查MIME类型是否在白名单中
        if (!isset(self::$allowedMimeTypes[$mimeType])) {
            return [false, '不支持的文件类型'];
        }

        // 验证扩展名与MIME类型匹配
        if (!in_array($extension, self::$allowedMimeTypes[$mimeType])) {
            return [false, '文件扩展名与MIME类型不匹配'];
        }

        // 检查文件大小（默认最大10MB）
        $maxSize = config('media.max_file_size', 10 * 1024 * 1024);
        if ($fileSize > $maxSize) {
            return [false, '文件大小超过限制'];
        }

        // 检查文件名长度
        if (strlen($filename) > 255) {
            return [false, '文件名过长'];
        }

        // 检查文件名是否包含危险字符
        if (preg_match('/[\x00-\x1f\x7f-\x9f\/\\\\:*?"<>|]/', $filename)) {
            return [false, '文件名包含非法字符'];
        }

        return [true, ''];
    }

    /**
     * 生成安全的文件名
     *
     * @param string $originalName 原始文件名
     * @return string 安全的文件名
     */
    public static function generateSecureFilename(string $originalName): string
    {
        // 清理原始文件名
        $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $cleanName = preg_replace('/_+/', '_', $cleanName);
        $cleanName = trim($cleanName, '_');

        // 如果清理后为空，使用默认名称
        if (empty($cleanName)) {
            $cleanName = 'file';
        }

        // 添加时间戳和随机数防止冲突
        $timestamp = time();
        $random = bin2hex(random_bytes(4));

        return "{$timestamp}_{$random}_{$cleanName}";
    }

    /**
     * 验证请求是否来自合法来源
     *
     * @param Request $request 请求对象
     * @param array $allowedOrigins 允许的来源域名
     * @return bool 是否合法
     */
    public static function validateOrigin(Request $request, array $allowedOrigins = []): bool
    {
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');

        // 如果没有设置允许的来源，则信任所有来源（不推荐用于生产环境）
        if (empty($allowedOrigins)) {
            return true;
        }

        // 检查Origin头
        if ($origin && in_array($origin, $allowedOrigins)) {
            return true;
        }

        // 检查Referer头
        if ($referer) {
            foreach ($allowedOrigins as $allowedOrigin) {
                if (strpos($referer, $allowedOrigin) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 生成安全的随机字符串
     *
     * @param int $length 长度
     * @param string $type 类型：alphanum, numeric, alpha, hex
     * @return string 随机字符串
     */
    public static function generateSecureRandom(int $length = 32, string $type = 'alphanum'): string
    {
        $chars = match ($type) {
            'numeric' => '0123456789',
            'alpha' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'hex' => '0123456789abcdef',
            default => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
        };

        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $randomString;
    }

    /**
     * 验证密码强度
     *
     * @param string $password 密码
     * @param array $options 选项：min_length, require_uppercase, require_lowercase, require_numbers, require_symbols
     * @return array [是否通过, 错误信息]
     */
    public static function validatePasswordStrength(string $password, array $options = []): array
    {
        $defaults = [
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_numbers' => true,
            'require_symbols' => false,
        ];

        $options = array_merge($defaults, $options);

        // 检查最小长度
        if (strlen($password) < $options['min_length']) {
            return [false, "密码长度至少{$options['min_length']}位"];
        }

        // 检查大写字母
        if ($options['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            return [false, '密码必须包含大写字母'];
        }

        // 检查小写字母
        if ($options['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            return [false, '密码必须包含小写字母'];
        }

        // 检查数字
        if ($options['require_numbers'] && !preg_match('/[0-9]/', $password)) {
            return [false, '密码必须包含数字'];
        }

        // 检查特殊符号
        if ($options['require_symbols'] && !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
            return [false, '密码必须包含特殊符号'];
        }

        // 检查常见弱密码
        $weakPasswords = ['123456', 'password', '123456789', '12345678', '12345', '111111', '1234567', 'sunshine', 'qwerty', 'iloveyou'];
        if (in_array(strtolower($password), $weakPasswords)) {
            return [false, '密码过于简单，请选择更复杂的密码'];
        }

        return [true, ''];
    }

    /**
     * 创建安全的JSON响应
     *
     * @param mixed $data 响应数据
     * @param int $code 状态码
     * @param string $message 消息
     * @return Response JSON响应
     */
    public static function jsonResponse(mixed $data = null, int $code = 200, string $message = 'success'): Response
    {
        $response = [
            'code' => $code,
            'message' => $message,
            'timestamp' => time(),
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return json($response)->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * 记录安全事件日志
     *
     * @param string $event 事件类型
     * @param string $details 详细信息
     * @param array $context 上下文信息
     */
    public static function logSecurityEvent(string $event, string $details, array $context = []): void
    {
        $logData = [
            'event' => $event,
            'details' => $details,
            'timestamp' => time(),
            'ip' => request()->getRealIp(),
            'user_agent' => request()->header('User-Agent', ''),
            'context' => $context,
        ];

        \support\Log::warning('Security Event: ' . json_encode($logData));
    }
}
