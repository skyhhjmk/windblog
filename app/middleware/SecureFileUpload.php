<?php

namespace app\middleware;

use app\service\SecurityService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\Http\UploadFile;
use Webman\MiddlewareInterface;

/**
 * 安全的文件上传中间件
 *
 * 对所有文件上传请求进行严格的安全检查，防止恶意文件上传
 */
class SecureFileUpload implements MiddlewareInterface
{
    /**
     * 处理请求
     *
     * @param Request $request
     * @param callable $handler
     * @return Response
     */
    public function process(Request $request, callable $handler): Response
    {
        // 只处理包含文件上传的请求
        if (!$this->isFileUploadRequest($request)) {
            return $handler($request);
        }

        // 检查上传的文件
        $validationResult = $this->validateUploadedFiles($request);
        if (!$validationResult['valid']) {
            SecurityService::logSecurityEvent(
                'file_upload_blocked',
                $validationResult['message'],
                [
                    'ip' => $request->getRealIp(),
                    'user_agent' => $request->header('User-Agent', ''),
                    'files' => $request->file() ? array_keys($request->file()) : [],
                ]
            );

            return SecurityService::jsonResponse(
                null,
                400,
                $validationResult['message']
            );
        }

        // 文件验证通过，继续处理请求
        return $handler($request);
    }

    /**
     * 判断请求是否包含文件上传
     *
     * @param Request $request
     * @return bool
     */
    private function isFileUploadRequest(Request $request): bool
    {
        $files = $request->file();

        return !empty($files) && is_array($files);
    }

    /**
     * 验证上传的文件
     *
     * @param Request $request
     * @return array [是否有效, 错误信息]
     */
    private function validateUploadedFiles(Request $request): array
    {
        $files = $request->file();

        foreach ($files as $fieldName => $uploadedFiles) {
            // Webman的文件上传返回结构：单个文件时是UploadFile对象，多个文件时是数组
            // 我们需要统一处理这两种情况
            $filesToCheck = [];

            if (is_array($uploadedFiles)) {
                // 多个文件的情况
                foreach ($uploadedFiles as $file) {
                    if ($file instanceof UploadFile) {
                        $filesToCheck[] = $file;
                    }
                }
            } elseif ($uploadedFiles instanceof UploadFile) {
                // 单个文件的情况
                $filesToCheck[] = $uploadedFiles;
            }
            // 跳过无效的文件字段

            foreach ($filesToCheck as $file) {
                $result = $this->validateSingleFile($file);
                if (!$result['valid']) {
                    return $result;
                }
            }
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * 验证单个文件
     *
     * @param UploadFile $file
     * @return array [是否有效, 错误信息]
     */
    private function validateSingleFile(UploadFile $file): array
    {
        // 检查文件是否上传成功
        if (!$file->isValid()) {
            return ['valid' => false, 'message' => '文件上传失败'];
        }

        // 获取文件信息
        $originalName = $file->getUploadName();
        $mimeType = $file->getUploadMimeType();
        $fileSize = $file->getSize();
        $tmpPath = $file->getPathname();

        // 验证文件名
        if (empty($originalName)) {
            return ['valid' => false, 'message' => '文件名不能为空'];
        }

        // 使用SecurityService进行安全验证
        [$isSecure, $errorMessage] = SecurityService::validateFileSecurity(
            $originalName,
            $mimeType,
            $fileSize
        );

        if (!$isSecure) {
            return ['valid' => false, 'message' => $errorMessage];
        }

        // 检查临时文件是否存在且可读
        if (!file_exists($tmpPath) || !is_readable($tmpPath)) {
            return ['valid' => false, 'message' => '无法访问临时文件'];
        }

        // 检查文件内容（可选的安全检查）
        if ($this->shouldCheckFileContent($mimeType)) {
            $contentCheck = $this->checkFileContent($tmpPath, $mimeType);
            if (!$contentCheck['valid']) {
                return $contentCheck;
            }
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * 判断是否需要检查文件内容
     *
     * @param string $mimeType
     * @return bool
     */
    private function shouldCheckFileContent(string $mimeType): bool
    {
        // 只对某些类型进行内容检查
        $checkTypes = [
            'application/x-php',
            'application/x-httpd-php',
            'text/x-php',
            'text/html',
            'application/javascript',
            'text/javascript',
        ];

        return in_array($mimeType, $checkTypes);
    }

    /**
     * 检查文件内容
     *
     * @param string $filePath
     * @param string $mimeType
     * @return array [是否有效, 错误信息]
     */
    private function checkFileContent(string $filePath, string $mimeType): array
    {
        // 只检查前1KB的内容，避免大文件性能问题
        $content = file_get_contents($filePath, false, null, 0, 1024);

        if ($content === false) {
            return ['valid' => false, 'message' => '无法读取文件内容'];
        }

        // 检查PHP代码
        if (strpos($mimeType, 'php') !== false) {
            if (preg_match('/<\?(php|=)/i', $content)) {
                return ['valid' => false, 'message' => '文件包含PHP代码'];
            }
        }

        // 检查JavaScript代码
        if (strpos($mimeType, 'javascript') !== false) {
            if (preg_match('/<script|javascript:|eval\(|setTimeout\(|setInterval\(/i', $content)) {
                return ['valid' => false, 'message' => '文件包含潜在恶意脚本'];
            }
        }

        // 检查HTML内容
        if ($mimeType === 'text/html') {
            if (preg_match('/<script|javascript:|vbscript:|onload=|onerror=/i', $content)) {
                return ['valid' => false, 'message' => 'HTML文件包含潜在恶意脚本'];
            }
        }

        return ['valid' => true, 'message' => ''];
    }
}
