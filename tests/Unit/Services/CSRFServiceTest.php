<?php

namespace Tests\Unit\Services;

use app\service\CSRFService;
use PHPUnit\Framework\TestCase;

/**
 * CSRFService 单元测试
 *
 * 注意：这是简化版测试，测试不依赖 Request 的方法
 */
class CSRFServiceTest extends TestCase
{
    protected CSRFService $csrfService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csrfService = new CSRFService();
    }

    /**
     * 测试 CSRFService 实例化
     */
    public function testInstantiation(): void
    {
        $this->assertInstanceOf(CSRFService::class, $this->csrfService);
    }

    /**
     * 测试设置 token 过期时间
     */
    public function testSetTokenExpire(): void
    {
        $result = $this->csrfService->setTokenExpire(7200);

        $this->assertInstanceOf(CSRFService::class, $result);
        $this->assertSame($this->csrfService, $result);
    }

    /**
     * 测试设置一次性 token
     */
    public function testSetOneTimeToken(): void
    {
        $result = $this->csrfService->setOneTimeToken(true);

        $this->assertInstanceOf(CSRFService::class, $result);
        $this->assertSame($this->csrfService, $result);
    }

    /**
     * 测试设置绑定值
     */
    public function testSetBindToValue(): void
    {
        $result = $this->csrfService->setBindToValue(true, 'custom_field');

        $this->assertInstanceOf(CSRFService::class, $result);
        $this->assertSame($this->csrfService, $result);
    }

    /**
     * 测试链式调用
     */
    public function testMethodChaining(): void
    {
        $result = $this->csrfService
            ->setTokenExpire(7200)
            ->setOneTimeToken(true)
            ->setBindToValue(false);

        $this->assertInstanceOf(CSRFService::class, $result);
        $this->assertSame($this->csrfService, $result);
    }

    /**
     * 测试多次设置 token 过期时间
     */
    public function testSetTokenExpireMultipleTimes(): void
    {
        $this->csrfService->setTokenExpire(1800);
        $this->csrfService->setTokenExpire(3600);
        $result = $this->csrfService->setTokenExpire(7200);

        $this->assertInstanceOf(CSRFService::class, $result);
    }

    /**
     * 测试不同的一次性 token 设置
     */
    public function testSetOneTimeTokenToggle(): void
    {
        $this->csrfService->setOneTimeToken(true);
        $result = $this->csrfService->setOneTimeToken(false);

        $this->assertInstanceOf(CSRFService::class, $result);
    }

    /**
     * 测试绑定值字段名设置
     */
    public function testSetBindToValueWithDifferentFields(): void
    {
        $this->csrfService->setBindToValue(true, 'user_id');
        $this->csrfService->setBindToValue(true, 'session_id');
        $result = $this->csrfService->setBindToValue(true, 'custom_id');

        $this->assertInstanceOf(CSRFService::class, $result);
    }

    /**
     * 测试禁用绑定值
     */
    public function testDisableBindToValue(): void
    {
        $this->csrfService->setBindToValue(true, 'user_id');
        $result = $this->csrfService->setBindToValue(false);

        $this->assertInstanceOf(CSRFService::class, $result);
    }

    /**
     * 测试默认配置
     */
    public function testDefaultConfiguration(): void
    {
        $service = new CSRFService();

        $this->assertInstanceOf(CSRFService::class, $service);
    }

    /**
     * 测试配置组合
     */
    public function testConfigurationCombinations(): void
    {
        // 组合 1
        $result1 = $this->csrfService
            ->setTokenExpire(3600)
            ->setOneTimeToken(false);

        $this->assertInstanceOf(CSRFService::class, $result1);

        // 组合 2
        $result2 = $this->csrfService
            ->setOneTimeToken(true)
            ->setBindToValue(true, 'user_id');

        $this->assertInstanceOf(CSRFService::class, $result2);

        // 组合 3
        $result3 = $this->csrfService
            ->setTokenExpire(7200)
            ->setOneTimeToken(true)
            ->setBindToValue(true, 'session_id');

        $this->assertInstanceOf(CSRFService::class, $result3);
    }
}
