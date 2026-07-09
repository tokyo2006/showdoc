<?php

namespace Tests\Agent;

use PHPUnit\Framework\TestCase;

/**
 * Agent 循环语义与安全边界测试（开源版）
 *
 * 覆盖用例：
 * - TC-475: tool_rounds_limit 计数粒度（按响应轮数，非工具次数）
 * - TC-479: 幻觉工具名穿过 denylist 的行为（黑名单 vs 白名单）
 * - TC-478: 不平衡/嵌套 EDIT 标记处理（验证修复后孤立标记被清除）
 *
 * 这些用例通过反射直接测试私有方法，不依赖 mock LLM server。
 *
 * 与主版的差异：
 *   - 纯逻辑用例，开源版 AgentHelper 的 isToolAllowed / isConsecutiveLimit /
 *     extractEditContent 与主版一致，无需裁剪。
 *   - 注释中引用的 CreditPipelineTest（积分流水）在开源版不存在，已改为
 *     「完整 agent 流程集成测试」。
 */
class AgentLoopEdgeCasesTest extends TestCase
{
    /** @var object Host 实例（use AgentHelper） */
    private $host;

    protected function setUp(): void
    {
        $this->host = new class {
            use \App\Common\Helper\AgentHelper;

            public string $aiSystemPrompt = '';
            public string $aiServiceUrl = '';
            public string $aiServiceToken = '';
            public string $aiModelName = '';
            public string $aiWelcomeMessage = '';
        };
    }

    // ==================================================================
    //  TC-479: 幻觉工具名穿过 denylist（黑名单设计审视）
    // ==================================================================

    /** TC-479: 未知工具名（不在黑名单）对所有角色返回 true → 穿过到 MCP */
    public function testUnknownToolNamePassesDenylistForAllRoles(): void
    {
        // admin
        $this->assertTrue($this->invoke('isToolAllowed', ['fake_tool_xyz', 'admin']));
        // writer
        $this->assertTrue($this->invoke('isToolAllowed', ['fake_tool_xyz', 'writer']));
        // reader — 这是关键安全点：reader 也能穿过未知工具名
        $this->assertTrue($this->invoke('isToolAllowed', ['fake_tool_xyz', 'reader']));
        // guest
        $this->assertTrue($this->invoke('isToolAllowed', ['fake_tool_xyz', 'guest']));
    }

    /** TC-479: 已知 admin 工具对 reader 被拒（黑名单生效） */
    public function testKnownAdminToolRejectedForReader(): void
    {
        $this->assertFalse($this->invoke('isToolAllowed', ['delete_page', 'reader']));
        $this->assertFalse($this->invoke('isToolAllowed', ['delete_item', 'reader']));
    }

    /** TC-479: 已知 write 工具对 reader/guest 被拒 */
    public function testKnownWriteToolRejectedForReaderAndGuest(): void
    {
        $this->assertFalse($this->invoke('isToolAllowed', ['create_page', 'reader']));
        $this->assertFalse($this->invoke('isToolAllowed', ['create_page', 'guest']));
    }

    // ==================================================================
    //  TC-478: 不平衡/嵌套 EDIT 标记处理（验证修复）
    // ==================================================================

    /** TC-478: 孤立 START 标记（无匹配对）→ extractEditContent 返回 null
     *  孤立标记的清除发生在 AgentController 调用处的 else 分支（单独的 strip 步骤），
     *  此处验证检测逻辑（返回 null），清除逻辑在完整 agent 流程集成测试中经完整流程覆盖。
     */
    public function testExtractEditContentOrphanStartMarkerDetected(): void
    {
        $result = $this->invoke('extractEditContent', ['正文内容<<<EDIT_START>>>未闭合的内容']);
        $this->assertNull($result, '无匹配对应返回 null，由调用方 else 分支清除孤立标记');
    }

    /** TC-478: 完整匹配对正常提取 */
    public function testExtractEditContentNormalPairExtracted(): void
    {
        $result = $this->invoke('extractEditContent', ['前文<<<EDIT_START>>>编辑内容<<<EDIT_END>>>后文']);

        $this->assertNotNull($result);
        $this->assertEquals('编辑内容', $result['content']);
        $this->assertEquals('前文后文', $result['cleaned']);
    }

    /** TC-478: 嵌套标记（匹配对 + 残留孤立标记）→ cleaned 中孤立标记被清除 */
    public function testExtractEditContentNestedMarkersStripped(): void
    {
        // 匹配对 <<<EDIT_START>>>内容<<<EDIT_END>>> 后跟孤立 START 标记
        // 非贪婪匹配捕获"内容"，preg_replace 移除匹配对后孤立 START 残留 → 修复后应被 strip
        $result = $this->invoke('extractEditContent', ['前文<<<EDIT_START>>>内容<<<EDIT_END>>>后文<<<EDIT_START>>>残留']);

        $this->assertNotNull($result);
        $this->assertEquals('内容', $result['content']);
        // cleaned 不应残留任何 <<<EDIT_START>>> 或 <<<EDIT_END>>>
        $this->assertStringNotContainsString('<<<EDIT_START>>>', $result['cleaned'], 'cleaned 不应残留 START 标记');
        $this->assertStringNotContainsString('<<<EDIT_END>>>', $result['cleaned'], 'cleaned 不应残留 END 标记');
    }

    /** TC-478: 多个完整匹配对都正确提取 */
    public function testExtractEditContentMultiplePairsExtracted(): void
    {
        $result = $this->invoke('extractEditContent', ['<<<EDIT_START>>>块1<<<EDIT_END>>><<<EDIT_START>>>块2<<<EDIT_END>>>']);

        $this->assertNotNull($result);
        $this->assertStringContainsString('块1', $result['content']);
        $this->assertStringContainsString('块2', $result['content']);
        $this->assertEquals('', $result['cleaned']);
    }

    /** TC-478: 空编辑块返回 null */
    public function testExtractEditContentEmptyBlockReturnsNull(): void
    {
        $result = $this->invoke('extractEditContent', ['<<<EDIT_START>>><<<EDIT_END>>>']);
        $this->assertNull($result);
    }

    // ==================================================================
    //  TC-475: tool_rounds_limit 计数粒度（通过 isConsecutiveLimit 间接验证循环保护）
    // ==================================================================

    /** TC-475: 连续同类工具调用 ≤3 次返回 false（未超限） */
    public function testConsecutiveToolWithinLimit(): void
    {
        $this->assertFalse($this->invoke('isConsecutiveLimit', ['search_pages']));
        $this->assertFalse($this->invoke('isConsecutiveLimit', ['search_pages']));
        $this->assertFalse($this->invoke('isConsecutiveLimit', ['search_pages']));
    }

    /** TC-475: 第 4 次连续同类工具返回 true（超限） */
    public function testConsecutiveToolExceedsLimitOnFourth(): void
    {
        $this->invoke('isConsecutiveLimit', ['get_page']);
        $this->invoke('isConsecutiveLimit', ['get_page']);
        $this->invoke('isConsecutiveLimit', ['get_page']);
        $this->assertTrue($this->invoke('isConsecutiveLimit', ['get_page']));
    }

    /** TC-475: 切换工具后计数重置 */
    public function testConsecutiveToolResetOnSwitch(): void
    {
        $this->invoke('isConsecutiveLimit', ['get_page']);
        $this->invoke('isConsecutiveLimit', ['get_page']);
        $this->invoke('isConsecutiveLimit', ['get_page']);
        // 切换到不同工具
        $this->assertFalse($this->invoke('isConsecutiveLimit', ['search_pages']));
    }

    // ==================================================================
    //  辅助方法
    // ==================================================================

    private function invoke(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($this->host, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->host, $args);
    }
}
