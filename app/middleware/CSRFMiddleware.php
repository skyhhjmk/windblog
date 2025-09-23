<?php

namespace app\middleware;

use app\annotation\CSRFVerify;
use app\service\CSRFService;
use ReflectionClass;
use ReflectionMethod;
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
     * @param Request $request 请求对象
     * @param callable $handler 下一个处理器
     * @return Response
     */
    public function process(Request $request, callable $handler): Response
    {
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
                if ($request->expectsJson()) {
                    return json(['code' => 403, 'msg' => $annotation->message], 403);
                }
                
                return response($annotation->message, 403);
            }
            
            // 验证通过，继续处理请求
            return $handler($request);
            
        } catch (\Exception $e) {
            // 反射异常或其他错误，记录日志并继续处理
            // 在实际生产环境中应该记录这个异常
            return $handler($request);
        }
    }

}