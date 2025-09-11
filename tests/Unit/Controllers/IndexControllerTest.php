<?php

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use app\controller\IndexController;
use support\Request;
use Workerman\Protocols\Http\Session;

/**
 * IndexController测试用例
 * 
 * 用于测试IndexController中的方法，特别是getSession方法
 */
class IndexControllerTest extends TestCase
{
    /**
     * 测试getSession方法是否能正确返回session信息
     */
    public function testGetSessionMethod()
    {
        // 创建mock request对象
        $request = $this->createMock(Request::class);
        
        // 创建mock session对象
        $session = $this->createMock(Session::class);
        
        // 设置期望的session行为
        $session->expects($this->once())
            ->method('all')
            ->willReturn(['test_key' => 'test_value']);
            
        // 设置request的session方法返回mock session
        $request->expects($this->once())
            ->method('session')
            ->willReturn($session);
            
        // 创建IndexController实例
        $controller = new IndexController();
        
        // 调用getSession方法
        $response = $controller->getSession($request);
        
        // 验证返回结果
        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());
    }
    
    /**
     * 测试getSession方法在session不可用时的处理
     */
    public function testGetSessionMethodWhenSessionNotAvailable()
    {
        // 创建mock request对象
        $request = $this->createMock(Request::class);
        
        // 设置request的session方法返回null
        $request->expects($this->once())
            ->method('session')
            ->willReturn(null);
            
        // 创建IndexController实例
        $controller = new IndexController();
        
        // 调用getSession方法
        $response = $controller->getSession($request);
        
        // 验证返回结果
        $this->assertNotNull($response);
        $this->assertEquals(500, $response->getStatusCode());
    }
}