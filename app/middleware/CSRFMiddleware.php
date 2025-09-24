<?php

namespace app\middleware;

use app\annotation\CSRFVerify;
use app\service\CSRFService;
use ReflectionClass;
use ReflectionMethod;
use support\view\Raw;
use support\view\Twig;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

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
     */
    public function process(Request $request, callable $handler): Response
    {
        // 首先检查是否为跨域请求
        if ($this->isCrossOriginRequest($request)) {
            // 检查是否携带Access Token
            if (!$this->hasValidAccessToken($request)) {
                return response('跨域请求必须携带Access Token', 403);
            }
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

                // 如果是字符串，直接返回响应
                if (is_string($ifFailedConfig)) {
                    return response($ifFailedConfig);
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
                        default:
                        case 'json':
                            return response(json_encode($responseBody ?? 'CSRF Token validate failed.'),
                                $responseCode ?? 403, ['Content-Type' => 'application/json; charset=utf-8']);
                            break;
                        case 'view':
                            [$template, $vars, $app, $plugin] = template_inputs($responseBody ?? 'error/403', $args ?? [], null, null);
                            $handler = \config($plugin ? "plugin.$plugin.view.handler" : 'view.handler');
                            return new Response(200, [], $handler::render($template, $vars, $app, $plugin));
                            break;
                        case 'raw_view':
                            return new Response(200, [], Raw::render(...template_inputs(
                                $responseBody ?? 'error/403', $args ?? [], null, null)));
                            break;
                        case 'twig_view':
                            return new Response(200, [], Twig::render(...template_inputs(
                                $responseBody ?? 'error/403', $args ?? [], null, null)));
                            break;
                        case 'text':
                            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8']);
                            break;
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

            // 验证通过，继续处理请求
            return $handler($request);

        } catch (\Exception $e) {
            // 反射异常或其他错误，记录日志并继续处理
            // 在实际生产环境中应该记录这个异常
            return $handler($request);
        }
    }

    /**
     * 检测是否为跨域请求
     *
     * @param Request $request 请求对象
     * @return bool
     */
    private function isCrossOriginRequest(Request $request): bool
    {
        $origin = $request->header('Origin');
        $host = $request->header('Host');
        
        // 如果没有Origin头，不是跨域请求
        if (!$origin) {
            return false;
        }

        // 解析Origin和Host进行比较
        $originParts = parse_url($origin);
        $hostParts = parse_url('http://' . $host);

        // 比较协议、域名和端口
        $originHost = ($originParts['scheme'] ?? 'http') . '://' . ($originParts['host'] ?? '');
        $currentHost = ($hostParts['scheme'] ?? 'http') . '://' . ($hostParts['host'] ?? '');

        // 如果端口不同，也需要考虑（这里简化处理）
        $originPort = $originParts['port'] ?? ($originParts['scheme'] === 'https' ? 443 : 80);
        $currentPort = $hostParts['port'] ?? ($hostParts['scheme'] === 'https' ? 443 : 80);

        return $originHost !== $currentHost || $originPort !== $currentPort;
    }

    /**
     * 检查是否携带有效的Access Token
     *
     * @param Request $request 请求对象
     * @return bool
     */
    private function hasValidAccessToken(Request $request): bool
    {
        // 从Authorization头获取token
        $authHeader = $request->header('Authorization');
        if ($authHeader && preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            return $this->validateAccessToken($token);
        }

        // 从查询参数获取token
        $token = $request->get('access_token');
        if ($token) {
            return $this->validateAccessToken($token);
        }

        // 从POST数据获取token
        $token = $request->post('access_token');
        if ($token) {
            return $this->validateAccessToken($token);
        }

        return false;
    }

    /**
     * 验证Access Token的有效性
     *
     * @param string $token Access Token
     * @return bool
     */
    private function validateAccessToken(string $token): bool
    {
        // 这里实现简单的token验证逻辑
        // 在实际项目中，应该连接到认证服务或数据库验证token
        
        // 示例：简单的格式验证（至少32字符的hex字符串）
        if (strlen($token) < 32 || !ctype_xdigit($token)) {
            return false;
        }

        // 示例：检查token是否在有效期内（这里简化处理）
        // 实际项目中应该检查token的过期时间
        
        return true;
    }
}