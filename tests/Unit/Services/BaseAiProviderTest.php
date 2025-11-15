<?php

namespace Tests\Unit\Services;

use app\service\ai\BaseAiProvider;
use Generator;
use PHPUnit\Framework\TestCase;

class BaseAiProviderTest extends TestCase
{
    /**
     * 测试超时时间从配置中读取，未配置时使用默认值 30 秒
     */
    public function testGetTimeoutFromConfigOrDefault(): void
    {
        $providerDefault = new DummyAiProvider([]);
        $this->assertSame(30, $providerDefault->publicGetTimeout());

        $providerCustom = new DummyAiProvider(['timeout' => 10]);
        $this->assertSame(10, $providerCustom->publicGetTimeout());
    }

    /**
     * 测试温度优先从 options 读取，其次从配置读取，最后使用默认 0.7
     */
    public function testGetTemperatureOrder(): void
    {
        $provider = new DummyAiProvider(['temperature' => 1.2]);

        // 配置值
        $this->assertEquals(1.2, $provider->publicGetTemperature());

        // options 覆盖配置
        $this->assertEquals(0.3, $provider->publicGetTemperature(['temperature' => 0.3]));

        // 未提供配置与 options 时使用默认值
        $providerDefault = new DummyAiProvider([]);
        $this->assertEquals(0.7, $providerDefault->publicGetTemperature());
    }

    /**
     * 测试 max_tokens 支持 null 或整数，并且 options 覆盖配置
     */
    public function testGetMaxTokens(): void
    {
        $provider = new DummyAiProvider([]);
        $this->assertNull($provider->publicGetMaxTokens());

        $providerWithConfig = new DummyAiProvider(['max_tokens' => 1000]);
        $this->assertSame(1000, $providerWithConfig->publicGetMaxTokens());

        $this->assertSame(50, $providerWithConfig->publicGetMaxTokens(['max_tokens' => 50]));
    }

    /**
     * 测试模型 ID 的优先级：options > custom_model_id > model > 默认模型
     */
    public function testGetModelPriority(): void
    {
        $provider = new DummyAiProvider([
            'custom_model_id' => 'custom-model',
            'model' => 'config-model',
        ]);

        // options 优先
        $this->assertSame('option-model', $provider->publicGetModel(['model' => 'option-model']));

        // 其次 custom_model_id
        $this->assertSame('custom-model', $provider->publicGetModel());

        // 无 custom_model_id 时使用 model
        $providerNoCustom = new DummyAiProvider(['model' => 'config-model']);
        $this->assertSame('config-model', $providerNoCustom->publicGetModel());

        // 都没有时使用默认模型
        $providerDefault = new DummyAiProvider([]);
        $this->assertSame('dummy-default-model', $providerDefault->publicGetModel());
    }

    /**
     * 测试 extractThinkFromText 在没有 <think> 标签时保持内容不变
     */
    public function testExtractThinkFromTextWithoutThinkBlocks(): void
    {
        $provider = new DummyAiProvider([]);
        $text = '这是普通的回复内容。';

        $result = $provider->publicExtractThinkFromText($text);

        $this->assertSame($text, $result['content']);
        $this->assertSame('', $result['thinking']);
    }

    /**
     * 测试 extractThinkFromText 可提取并移除多个 <think> 块
     */
    public function testExtractThinkFromTextWithMultipleThinkBlocks(): void
    {
        $provider = new DummyAiProvider([]);
        $text = '<think>第一次思考</think>可见内容<think>第二次思考</think>';

        $result = $provider->publicExtractThinkFromText($text);

        // 所有 <think> 内容被合并到 thinking 字段
        $this->assertStringContainsString('第一次思考', $result['thinking']);
        $this->assertStringContainsString('第二次思考', $result['thinking']);

        // content 中不应再包含 think 标签
        $this->assertStringNotContainsString('<think>', $result['content']);
        $this->assertStringNotContainsString('</think>', $result['content']);

        // 原本可见内容应保留
        $this->assertStringContainsString('可见内容', $result['content']);
    }

    /**
     * 测试 validateThinkBlocks：标签成对且顺序正确时应返回 valid=true
     */
    public function testValidateThinkBlocksValidCases(): void
    {
        $provider = new DummyAiProvider([]);

        // 无任何标签
        $res = $provider->publicValidateThinkBlocks('普通文本');
        $this->assertTrue($res['valid']);

        // 正常成对
        $res = $provider->publicValidateThinkBlocks('<think>a</think> 中间 <think>b</think>');
        $this->assertTrue($res['valid']);

        // 嵌套（虽然一般不会出现，但算法仍能处理）
        $res = $provider->publicValidateThinkBlocks('<think>外层 <think>内层</think> 结束</think>');
        $this->assertTrue($res['valid']);
    }

    /**
     * 测试 validateThinkBlocks：不成对或顺序错误应返回 valid=false
     */
    public function testValidateThinkBlocksInvalidCases(): void
    {
        $provider = new DummyAiProvider([]);

        // 只有开标签
        $res = $provider->publicValidateThinkBlocks('开头 <think> 没有结束');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('不完整', $res['error']);

        // 只有闭标签
        $res = $provider->publicValidateThinkBlocks('只有关闭 </think>');
        $this->assertFalse($res['valid']);

        // 闭标签出现在开标签之前
        $res = $provider->publicValidateThinkBlocks('</think> 先关后开 <think>');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('顺序不正确', $res['error']);
    }

    /**
     * 测试默认功能特性与 fetchModels 返回结构
     */
    public function testSupportedFeaturesAndFetchModelsDefaults(): void
    {
        $provider = new DummyAiProvider([]);

        $features = $provider->getSupportedFeatures();
        $this->assertArrayHasKey('streaming', $features);
        $this->assertFalse($features['streaming']);
        $this->assertArrayHasKey('multimodal', $features);
        $this->assertContains('text', $features['multimodal']);

        $modelsResult = $provider->fetchModels();
        $this->assertFalse($modelsResult['ok']);
        $this->assertArrayHasKey('error', $modelsResult);
    }

    /**
     * 默认流式调用实现应返回一个空的 Generator
     */
    public function testCallStreamDefaultReturnsEmptyGenerator(): void
    {
        $provider = new DummyAiProvider([]);

        $stream = $provider->callStream('dummy-task');
        $this->assertInstanceOf(Generator::class, $stream);
        $this->assertSame([], iterator_to_array($stream));
    }
}

/**
 * 用于测试 BaseAiProvider 受保护方法的简单实现类
 */
class DummyAiProvider extends BaseAiProvider
{
    public function getId(): string
    {
        return 'dummy';
    }

    public function getName(): string
    {
        return 'Dummy Provider';
    }

    public function getType(): string
    {
        return 'dummy';
    }

    public function call(string $task, array $params = [], array $options = []): array
    {
        return ['ok' => true, 'result' => 'dummy'];
    }

    public function getSupportedTasks(): array
    {
        return ['dummy_task'];
    }

    public function getConfigFields(): array
    {
        return [];
    }

    public function validateConfig(array $config): array
    {
        return ['valid' => true];
    }

    /**
     * 覆盖默认模型，便于测试
     */
    public function getDefaultModel(): string
    {
        return 'dummy-default-model';
    }

    // 以下方法公开调用受保护方法，方便测试

    public function publicGetTimeout(): int
    {
        return $this->getTimeout();
    }

    public function publicGetTemperature(array $options = []): float
    {
        return $this->getTemperature($options);
    }

    public function publicGetMaxTokens(array $options = []): ?int
    {
        return $this->getMaxTokens($options);
    }

    public function publicGetModel(array $options = []): string
    {
        return $this->getModel($options);
    }

    public function publicExtractThinkFromText(string $text): array
    {
        return $this->extractThinkFromText($text);
    }

    public function publicValidateThinkBlocks(string $text): array
    {
        return $this->validateThinkBlocks($text);
    }
}
