<?php

namespace Tests\Unit\Services;

use app\service\ai\AiProviderTemplates;
use PHPUnit\Framework\TestCase;

class AiProviderTemplatesTest extends TestCase
{
    /**
     * 基本模板列表应包含若干预置提供方，例如 openai / azure_openai 等
     */
    public function testGetTemplatesContainsKnownProviders(): void
    {
        $templates = AiProviderTemplates::getTemplates();

        $this->assertIsArray($templates);
        $this->assertArrayHasKey('openai', $templates);
        $this->assertArrayHasKey('azure_openai', $templates);
        $this->assertArrayHasKey('claude', $templates);
        $this->assertArrayHasKey('gemini', $templates);
        $this->assertArrayHasKey('deepseek', $templates);
        $this->assertArrayHasKey('zhipu', $templates);
        $this->assertArrayHasKey('local_echo', $templates);
        $this->assertArrayHasKey('custom', $templates);

        $openai = $templates['openai'];
        $this->assertSame('OpenAI', $openai['name']);
        $this->assertSame('openai', $openai['type']);
        $this->assertArrayHasKey('config_template', $openai);
        $this->assertArrayHasKey('base_url', $openai['config_template']);
        $this->assertArrayHasKey('api_key', $openai['config_template']);
    }

    /**
     * 获取单个模板：存在返回数组，不存在返回 null
     */
    public function testGetTemplateById(): void
    {
        $openai = AiProviderTemplates::getTemplate('openai');
        $this->assertIsArray($openai);
        $this->assertSame('openai', $openai['type']);
        $this->assertSame('OpenAI', $openai['name']);

        $unknown = AiProviderTemplates::getTemplate('unknown-template-id');
        $this->assertNull($unknown);
    }

    /**
     * generateConfig 应当将模板默认配置和用户配置合并，用户配置优先
     */
    public function testGenerateConfigMergesTemplateAndUserConfig(): void
    {
        $config = AiProviderTemplates::generateConfig('openai', [
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
            'timeout' => 99,
        ]);

        $this->assertSame('test-key', $config['api_key']);
        $this->assertSame('gpt-4o', $config['model']);
        $this->assertSame(99, $config['timeout']);

        // 模板中的其他默认字段仍然存在
        $this->assertArrayHasKey('base_url', $config);
        $this->assertArrayHasKey('temperature', $config);
    }

    /**
     * 当模板不存在时，generateConfig 应返回用户配置本身
     */
    public function testGenerateConfigForUnknownTemplateReturnsUserConfig(): void
    {
        $userConfig = ['api_key' => 'only-user-config'];
        $config = AiProviderTemplates::generateConfig('non-exists', $userConfig);

        $this->assertSame($userConfig, $config);
    }

    /**
     * getTemplateList 应返回简化后的模板列表，包含 id/name/description/icon/type 等字段
     */
    public function testGetTemplateListMatchesTemplates(): void
    {
        $templates = AiProviderTemplates::getTemplates();
        $list = AiProviderTemplates::getTemplateList();

        $this->assertIsArray($list);
        $this->assertNotEmpty($list);

        $idsInList = array_column($list, 'id');

        foreach (array_keys($templates) as $id) {
            $this->assertContains($id, $idsInList, sprintf('模板 %s 应该出现在 getTemplateList 返回结果中', $id));
        }

        foreach ($list as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('description', $item);
            $this->assertArrayHasKey('icon', $item);
            $this->assertArrayHasKey('type', $item);
        }
    }
}
