<?php

namespace app\middleware;

use app\annotation\CSRFVerify;
use app\service\CSRFService;

use function config;

use Exception;
use ReflectionClass;
use ReflectionMethod;
use support\Log;
use support\view\Raw;
use support\view\Twig;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * CSRF验证中间件
 *
 * 处理带有CSRFVerify注解的控制器方法验证
 */
class CSRFMiddleware implements MiddlewareInterface
{
    /**
     * 中间件处理逻辑
     *
     * @param Request  $request 请求对象
     * @param callable $handler 下一个处理器
     *
     * @return Response
     * @throws Throwable
     */
    public function process(Request $request, callable $handler): Response
    {
        // 首先检查是否为跨域请求
        if ($this->isCrossOriginRequest($request)) {
            // 禁用Access Token功能
            return response('跨域请求不被支持', 403);
        }

        // 如果没有控制器或方法，直接跳过
        if (!$request->controller || !$request->action) {
            return $handler($request);
        }

        try {
            $controllerClass = $request->controller;
            $actionMethod = $request->action;

            // 检查控制器类是否存在
            if (!class_exists($controllerClass)) {
                return $handler($request);
            }

            // 检查方法是否存在
            if (!method_exists($controllerClass, $actionMethod)) {
                return $handler($request);
            }

            // 获取类级别注解
            $classReflection = new ReflectionClass($controllerClass);
            $classAnnotations = $classReflection->getAttributes(CSRFVerify::class);

            // 获取方法级别注解
            $methodReflection = new ReflectionMethod($controllerClass, $actionMethod);
            $methodAnnotations = $methodReflection->getAttributes(CSRFVerify::class);

            // 优先使用方法级别注解，如果没有则使用类级别注解
            $annotations = !empty($methodAnnotations) ? $methodAnnotations : $classAnnotations;

            if (empty($annotations)) {
                // 没有CSRF验证注解，直接继续处理
                return $handler($request);
            }

            // 获取第一个注解实例
            $annotation = $annotations[0]->newInstance();

            // 检查当前请求方法是否需要验证
            $requestMethod = strtoupper($request->method());
            if (!in_array($requestMethod, $annotation->methods)) {
                return $handler($request);
            }

            // 创建CSRF服务实例并配置选项
            $csrfService = new CSRFService();
            $csrfService
                ->setTokenExpire($annotation->expire)
                ->setOneTimeToken($annotation->oneTime)
                ->setBindToValue($annotation->bindToValue, $annotation->bindField);

            // 验证CSRF token
            if (!$csrfService->validateToken($request, $annotation->tokenName)) {
                // CSRF token验证失败

                // 处理if_failed_config配置
                $ifFailedConfig = $annotation->if_failed_config;

                // 如果是字符串，根据请求类型返回响应（优先JSON）
                if (is_string($ifFailedConfig)) {
                    // 如果注解要求JSON，或请求期望JSON/为Ajax请求，则返回JSON
                    if (($annotation->jsonResponse ?? false)
                        || $request->expectsJson()
                        || strtolower($request->header('X-Requested-With') ?? '') === 'xmlhttprequest') {
                        return json(['code' => 403, 'msg' => $ifFailedConfig]);
                    }

                    // 否则返回文本，附带403状态码
                    return response($ifFailedConfig, 403, ['Content-Type' => 'text/plain; charset=utf-8']);
                }

                // 如果是数组，进行配置验证
                if (is_array($ifFailedConfig)) {
                    // 检查response_type
                    if (isset($ifFailedConfig['response_type'])) {
                        $responseType = $ifFailedConfig['response_type'];
                        $allowedTypes = ['json', 'view', 'raw_view', 'twig_view', 'text'];
                        if (!in_array($responseType, $allowedTypes)) {
                            // 无效的response_type，使用默认响应
                            return response('CSRF验证失败');
                        }
                    }

                    // 检查response_code
                    if (isset($ifFailedConfig['response_code'])) {
                        $responseCode = $ifFailedConfig['response_code'];
                        if (!is_int($responseCode) || $responseCode < 100 || $responseCode >= 600) {
                            // 无效的response_code，使用默认响应
                            return response('CSRF验证失败');
                        }
                    }

                    // 检查response_body
                    if (isset($ifFailedConfig['response_body'])) {
                        $responseBody = $ifFailedConfig['response_body'];
                        if (is_string($responseBody)) {
                            // 无效的response_body，使用默认响应
                            return response($responseBody);
                        }
                    }

                    // 检查args数组
                    if (isset($ifFailedConfig['args'])) {
                        $args = $ifFailedConfig['args'];
                        if (!is_array($args)) {
                            // 无效的args，使用默认响应
                            return response('CSRF验证失败');
                        }
                    }

                    // 预留响应处理部分 - 用户自行实现
                    // 这里可以根据配置的response_type、response_code、response_body、args进行响应处理
                    // 例如：return json(['code' => $responseCode, 'msg' => $responseBody]);
                    switch ($responseType ?? 'json') {
                        case 'view':
                            [$template, $vars, $app, $plugin] = template_inputs($responseBody ?? 'error/403', $args ?? [], null, null);
                            $handler = config($plugin ? "plugin.$plugin.view.handler" : 'view.handler');

                            return new Response(200, [], $handler::render($template, $vars, $app, $plugin));

                        case 'raw_view':
                            return new Response(200, [], Raw::render(...template_inputs(
                                $responseBody ?? 'error/403',
                                $args ?? [],
                                null,
                                null
                            )));

                        case 'twig_view':
                            return new Response(200, [], Twig::render(...template_inputs(
                                $responseBody ?? 'error/403',
                                $args ?? [],
                                null,
                                null
                            )));

                        case 'text':
                            return new Response(
                                $responseCode ?? 403,
                                ['Content-Type' => 'text/plain; charset=utf-8'],
                                $responseBody ?? 'CSRF Token validation failed'
                            );

                        case 'json':
                        default:
                            return response(
                                json_encode($responseBody ?? 'CSRF Token validate failed.'),
                                $responseCode ?? 403,
                                ['Content-Type' => 'application/json; charset=utf-8']
                            );
                    }
                }

                // 如果if_failed_config是可调用对象，执行它来获取Response
                if (is_callable($annotation->if_failed_config)) {
                    // 如果是数组格式的可调用对象 [class, method]，支持传递额外参数
                    if (is_array($annotation->if_failed_config) && count($annotation->if_failed_config) === 2) {
                        return call_user_func($annotation->if_failed_config, $request, $annotation);
                    }

                    return call_user_func($annotation->if_failed_config, $request);
                }

                // 优先使用自定义响应处理器
                if ($annotation->responseHandler && is_callable($annotation->responseHandler)) {
                    return call_user_func($annotation->responseHandler, $request, $annotation->if_failed_config);
                }

                // 其次检查是否总是返回JSON响应
                if ($annotation->jsonResponse) {
                    return json(['code' => 403, 'msg' => is_string($annotation->if_failed_config) ? $annotation->if_failed_config : 'CSRF验证失败']);
                }

                // 最后根据请求类型返回相应响应
                if ($request->expectsJson()) {
                    return json(['code' => 403, 'msg' => is_string($annotation->if_failed_config) ? $annotation->if_failed_config : 'CSRF验证失败']);
                }

                return response(is_string($annotation->if_failed_config) ? $annotation->if_failed_config : 'CSRF验证失败');
            }

            // 验证通过,继续处理请求
            return $handler($request);

        } catch (Exception $e) {
            // 反射异常或其他错误,记录日志
            Log::warning('CSRFMiddleware 处理异常: ' . $e->getMessage(), [
                'controller' => $request->controller ?: 'unknown',
                'action' => $request->action ?: 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);

            // 异常情况下继续处理请求（避免阻塞正常访问）
            return $handler($request);
        }
    }

    /**
     * 检测是否为跨域请求
     *
     * @param Request $request 请求对象
     *
     * @return bool
     */
    private function isCrossOriginRequest(Request $request): bool
    {
        $origin = $request->header('Origin');
        $host = $request->header('Host');

        // 如果没有Origin头,不是跨域请求
        if (!$origin) {
            return false;
        }

        // 解析Origin
        $originParts = parse_url($origin);
        if (!$originParts || !isset($originParts['host'])) {
            return false;
        }

        // 获取当前请求的协议（支持反向代理）
        $currentScheme = 'http';
        if ($request->header('X-Forwarded-Proto') === 'https' ||
            $request->header('X-Forwarded-Ssl') === 'on' ||
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
            $currentScheme = 'https';
        }

        // 构建当前Host的完整URL
        $currentHost = $currentScheme . '://' . $host;
        $currentParts = parse_url($currentHost);

        // 比较协议、域名和端口
        $originScheme = $originParts['scheme'] ?? 'http';
        $originHostname = $originParts['host'];
        $originPort = $originParts['port'] ?? ($originScheme === 'https' ? 443 : 80);

        $currentHostname = $currentParts['host'] ?? $host;
        $currentPort = $currentParts['port'] ?? ($currentScheme === 'https' ? 443 : 80);

        return $originScheme !== $currentScheme ||
            $originHostname !== $currentHostname ||
            $originPort !== $currentPort;
    }
}
