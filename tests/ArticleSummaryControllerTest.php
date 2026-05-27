<?php
/**
 * Unit tests for ArticleSummaryController
 * ArticleSummaryController 单元测试
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

// 加载 bootstrap 文件中的模拟类
require_once __DIR__ . '/../phpstan-bootstrap.php';

// 加载主要的类文件
require_once __DIR__ . '/../Controllers/ArticleSummaryController.php';

/**
 * Test class for ArticleSummaryController
 * ArticleSummaryController 测试类
 */
class ArticleSummaryControllerTest extends TestCase
{
    /**
     * Test that the controller class exists
     * 测试控制器类是否存在
     */
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists('FreshExtension_ArticleSummary_Controller'));
    }

    /**
     * Test that the controller extends Minz_ActionController
     * 测试控制器是否继承自 Minz_ActionController
     */
    public function testControllerExtendsMinzActionController(): void
    {
        $reflection = new \ReflectionClass('FreshExtension_ArticleSummary_Controller');
        $this->assertTrue($reflection->isSubclassOf('Minz_ActionController'));
    }

    /**
     * Test that the controller is final
     * 测试控制器是否为 final 类
     */
    public function testControllerIsFinal(): void
    {
        $reflection = new \ReflectionClass('FreshExtension_ArticleSummary_Controller');
        $this->assertTrue($reflection->isFinal());
    }

    /**
     * Test that the summarizeAction method exists
     * 测试 summarizeAction 方法是否存在
     */
    public function testSummarizeActionMethodExists(): void
    {
        $this->assertTrue(method_exists('FreshExtension_ArticleSummary_Controller', 'summarizeAction'));
    }

    /**
     * Test that the isEmpty method exists
     * 测试 isEmpty 方法是否存在
     */
    public function testIsEmptyMethodExists(): void
    {
        $this->assertTrue(method_exists('FreshExtension_ArticleSummary_Controller', 'isEmpty'));
    }

    /**
     * Test that the htmlToMarkdown method exists
     * 测试 htmlToMarkdown 方法是否存在
     */
    public function testHtmlToMarkdownMethodExists(): void
    {
        $this->assertTrue(method_exists('FreshExtension_ArticleSummary_Controller', 'htmlToMarkdown'));
    }

    /**
     * Test that stored checkbox values can be converted to booleans
     * 测试复选框配置值可以转换为布尔值
     */
    public function testToBoolConvertsStoredCheckboxValues(): void
    {
        $controller = new \FreshExtension_ArticleSummary_Controller();
        $method = new \ReflectionMethod('FreshExtension_ArticleSummary_Controller', 'toBool');

        $this->assertTrue($method->invoke($controller, '1'));
        $this->assertTrue($method->invoke($controller, 'true'));
        $this->assertTrue($method->invoke($controller, true));
        $this->assertFalse($method->invoke($controller, '0'));
        $this->assertFalse($method->invoke($controller, null));
    }

    /**
     * Test that thinking fields are only added to compatible OpenAI-style endpoints
     * 测试仅向兼容端点添加思考字段
     */
    public function testOpenAiCompatibleThinkingFieldSkipsOfficialOpenAiHost(): void
    {
        $controller = new \FreshExtension_ArticleSummary_Controller();
        $method = new \ReflectionMethod('FreshExtension_ArticleSummary_Controller', 'addOpenAiCompatibleThinking');

        $openAiBody = [];
        $method->invokeArgs($controller, [&$openAiBody, 'openai', 'https://api.openai.com', true]);
        $this->assertArrayNotHasKey('enable_thinking', $openAiBody);

        $compatibleBody = [];
        $method->invokeArgs($controller, [&$compatibleBody, 'openai', 'https://dashscope.aliyuncs.com/compatible-mode/v1', false]);
        $this->assertArrayHasKey('enable_thinking', $compatibleBody);
        $this->assertFalse($compatibleBody['enable_thinking']);
    }

    /**
     * Test that LM Studio uses reasoning effort fields instead of enable_thinking
     * 测试 LM Studio 使用 reasoning effort 字段而不是 enable_thinking
     */
    public function testLmStudioThinkingFieldUsesReasoningEffort(): void
    {
        $controller = new \FreshExtension_ArticleSummary_Controller();
        $method = new \ReflectionMethod('FreshExtension_ArticleSummary_Controller', 'addOpenAiCompatibleThinking');

        $disabledBody = [];
        $method->invokeArgs($controller, [&$disabledBody, 'lmstudio', 'http://localhost:1234/v1', false]);
        $this->assertSame('none', $disabledBody['reasoning_effort']);
        $this->assertSame(0, $disabledBody['reasoning_tokens']);
        $this->assertArrayNotHasKey('enable_thinking', $disabledBody);

        $enabledBody = [];
        $method->invokeArgs($controller, [&$enabledBody, 'lmstudio', 'http://localhost:1234/v1', true]);
        $this->assertSame('medium', $enabledBody['reasoning_effort']);
        $this->assertArrayNotHasKey('reasoning_tokens', $enabledBody);
        $this->assertArrayNotHasKey('enable_thinking', $enabledBody);
    }

    /**
     * Test that the processNode method exists
     * 测试 processNode 方法是否存在
     */
    public function testProcessNodeMethodExists(): void
    {
        $this->assertTrue(method_exists('FreshExtension_ArticleSummary_Controller', 'processNode'));
    }

    /**
     * Test that LM Studio can be used without an API key
     * 测试 LM Studio 可以不配置 API 密钥
     */
    public function testLmStudioAllowsEmptyApiKey(): void
    {
        $controller = new \FreshExtension_ArticleSummary_Controller();
        $method = new \ReflectionMethod('FreshExtension_ArticleSummary_Controller', 'allowsEmptyApiKey');

        $this->assertTrue($method->invoke($controller, 'lmstudio'));
        $this->assertTrue($method->invoke($controller, 'ollama'));
        $this->assertFalse($method->invoke($controller, 'openai'));
    }

    /**
     * Test that LM Studio base URLs are normalized to the chat completions endpoint
     * 娴嬭瘯 LM Studio 鍩虹 URL 浼氳鑼冨寲涓?chat completions 鎺ュ彛
     */
    public function testOpenAiChatCompletionsUrlNormalizesLmStudioUrls(): void
    {
        $controller = new \FreshExtension_ArticleSummary_Controller();
        $method = new \ReflectionMethod('FreshExtension_ArticleSummary_Controller', 'openAiChatCompletionsUrl');

        $expected = 'http://localhost:1234/v1/chat/completions';

        $this->assertSame($expected, $method->invoke($controller, 'http://localhost:1234'));
        $this->assertSame($expected, $method->invoke($controller, 'http://localhost:1234/'));
        $this->assertSame($expected, $method->invoke($controller, 'http://localhost:1234/v1'));
        $this->assertSame($expected, $method->invoke($controller, 'http://localhost:1234/v1/'));
        $this->assertSame($expected, $method->invoke($controller, 'http://localhost:1234/v1/chat/completions'));
    }
}
