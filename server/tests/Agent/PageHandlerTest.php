<?php

namespace Tests\Agent;

use PHPUnit\Framework\TestCase;
use App\Mcp\Handler\PageHandler;
use App\Mcp\McpException;
use App\Mcp\McpError;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * PageHandler 单元测试（开源版）
 *
 * 直接实例化 PageHandler，通过 execute() 调用操作，
 * 使用 SQLite 内存库验证所有页面操作。
 *
 * 覆盖用例：
 *   TC-170  get_page 多种查找方式
 *   TC-171  create_page 内容校验
 *   TC-172  update_page（content_hash 乐观锁）
 *   TC-173  upsert_page
 *   TC-174  batch_get_pages
 *   TC-175  delete_page
 *   TC-176  get_page_template
 *   TC-177  get_page_history / get_page_version
 *   TC-178  diff_page_versions / restore_page_version
 *
 * 与主版的差异（已裁剪/调整）：
 *   - page 改为单表存储：insertPage 只写 DB::table('page')（含 page_content 等全部列），
 *     不再双写 page 主表 + page_NN 分表。
 *   - page_history 改为单表：insertPageHistory 只写 DB::table('page_history')。
 *   - page_content 不压缩：直接存原始字符串。
 *   - setUp/tearDown 清理单表 page / page_history，移除 vip / item_whitelist
 *     （开源版无积分/敏感词，对应表不存在）。
 *   - TC-171.5 testCreatePageItemNotFound：开源版 createPage 先做 requireWritePermission，
 *     非存在项目会先抛 NOT_ITEM_MEMBER（而非“项目不存在”），断言改为仅期望 McpException。
 *   - TC-173.3 testUpsertPageDifferentCatalog：开源版 upsertPage 传入 cat_name 时按
 *     item_id + page_title 查找并更新/移动目录（而非新建），断言相应调整。
 */
class PageHandlerTest extends TestCase
{
    /** @var PageHandler */
    private PageHandler $handler;

    /** @var int 测试用户 UID */
    private int $uid = 100;

    /** @var int 测试项目 ID */
    private int $itemId = 1;

    /** @var int 测试页面 ID */
    private int $pageId = 200;

    // ------------------------------------------------------------------
    //  辅助方法
    // ------------------------------------------------------------------

    private function setupWriteToken(int $uid = 100): void
    {
        $this->handler->setTokenInfo([
            'uid'        => $uid,
            'permission' => 'write',
            'scope'      => 'all',
            'token_type' => 'user_token',
        ]);
    }

    private function setupReadToken(int $uid = 100): void
    {
        $this->handler->setTokenInfo([
            'uid'        => $uid,
            'permission' => 'read',
            'scope'      => 'all',
            'token_type' => 'user_token',
        ]);
    }

    private function insertUser(int $uid, string $username, int $groupid = 0): void
    {
        DB::table('user')->insert([
            'uid' => $uid, 'username' => $username, 'groupid' => $groupid,
        ]);
    }

    private function insertItem(int $itemId, string $name, int $uid = 100, int $itemType = 1): void
    {
        DB::table('item')->insert([
            'item_id' => $itemId, 'item_name' => $name,
            'uid' => $uid, 'item_type' => $itemType, 'is_del' => 0,
        ]);
    }

    /**
     * 插入测试页面到单表 page（开源版不压缩、不分表）
     */
    private function insertPage(
        int $pageId,
        int $itemId,
        string $title,
        string $content = '',
        int $catId = 0,
        int $authorUid = 100,
        string $authorUsername = 'testuser',
        int $isDraft = 0
    ): void {
        $now = time();

        DB::table('page')->insert([
            'page_id'         => $pageId,
            'item_id'         => $itemId,
            'cat_id'          => $catId,
            'page_title'      => $title,
            'page_content'    => $content,
            'is_del'          => 0,
            'is_draft'        => $isDraft,
            's_number'        => 99,
            'addtime'         => $now,
            'author_uid'      => $authorUid,
            'author_username' => $authorUsername,
        ]);
    }

    /**
     * 插入历史版本到单表 page_history（开源版不分表）
     *
     * addtime 随 versionId 递增，保证 PageHistory::getList 按 addtime desc 排序确定。
     */
    private function insertPageHistory(int $pageId, int $versionId, string $title, string $content, int $authorUid = 100, string $authorUsername = 'testuser'): void
    {
        DB::table('page_history')->insert([
            'page_history_id' => $versionId,
            'page_id'         => $pageId,
            'item_id'         => $this->itemId,
            'cat_id'          => 0,
            'page_title'      => $title,
            'page_content'    => $content,
            'page_comments'   => 'auto',
            's_number'        => 99,
            'addtime'         => time() + $versionId,
            'author_uid'      => $authorUid,
            'author_username' => $authorUsername,
        ]);
    }

    // ------------------------------------------------------------------
    //  setUp / tearDown
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->handler = new PageHandler();
        // 清除文件缓存（防止上一次运行或前一个测试的缓存影响当前测试）
        $this->clearFileCache();

        // 清空核心表数据（bootstrap 已创建完整 schema）
        $coreTables = ['item', 'page', 'page_history', 'user', 'catalog', 'item_member',
                       'team_item_member', 'file_page', 'single_page', 'options', 'item_ai_config'];
        foreach ($coreTables as $table) {
            DB::table($table)->delete();
        }

        // 默认测试数据
        $this->insertUser($this->uid, 'testuser');
        $this->insertItem($this->itemId, 'TestProject', $this->uid);
    }

    protected function tearDown(): void
    {
        $coreTables = ['item', 'page', 'page_history', 'user', 'catalog', 'item_member',
                       'team_item_member', 'file_page', 'single_page', 'options', 'item_ai_config'];
        foreach ($coreTables as $t) {
            DB::table($t)->delete();
        }

        // 清除文件缓存（CacheManager 在无 Redis 时 fallback 到文件缓存，
        // 导致测试间数据泄露）
        $this->clearFileCache();
    }

    private function clearFileCache(): void
    {
        $runtimePath = defined('RUNTIME_PATH') ? RUNTIME_PATH : dirname(__DIR__, 2) . '/app/Runtime/';
        $cacheDir = $runtimePath . 'cache';
        if (!is_dir($cacheDir)) {
            return;
        }
        foreach (glob($cacheDir . '/*.json') as $file) {
            @unlink($file);
        }
    }

    // ==================================================================
    //  TC-170  get_page
    // ==================================================================

    /** TC-170.1 通过 page_id 获取页面 */
    public function testGetPageByPageId(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '测试页面', 'hello world');

        $result = $this->handler->execute('get_page', ['page_id' => $this->pageId]);

        $this->assertEquals($this->pageId, $result['page_id']);
        $this->assertEquals('测试页面', $result['page_title']);
        $this->assertEquals($this->itemId, $result['item_id']);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('content_hash', $result);
        $this->assertArrayHasKey('page_url', $result);
    }

    /** TC-170.2 通过 item_id + page_title 获取页面 */
    public function testGetPageByItemAndTitle(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, 'API文档', 'content here');

        $result = $this->handler->execute('get_page', [
            'item_id'    => $this->itemId,
            'page_title' => 'API文档',
        ]);

        $this->assertEquals($this->pageId, $result['page_id']);
        $this->assertEquals('API文档', $result['page_title']);
    }

    /** TC-170.3 通过 unique_key 获取页面 */
    public function testGetPageByUniqueKey(): void
    {
        $this->setupWriteToken();
        $uniqueKey = 'test_unique_key_abc123';
        $this->insertPage($this->pageId, $this->itemId, '分享页', 'shared content');
        DB::table('single_page')->insert([
            'unique_key' => $uniqueKey, 'page_id' => $this->pageId, 'expire_time' => 0,
        ]);

        $result = $this->handler->execute('get_page', ['unique_key' => $uniqueKey]);

        $this->assertEquals($this->pageId, $result['page_id']);
        $this->assertEquals('分享页', $result['page_title']);
    }

    /** TC-170.4 页面不存在 */
    public function testGetPageNotFound(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->handler->execute('get_page', ['page_id' => 99999]);
    }

    /** TC-170.5 草稿页面：非作者访问报错 */
    public function testGetPageDraftNotAuthor(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '草稿页', 'draft content', 0, 200, 'other_author', 1);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('草稿');
        $this->handler->execute('get_page', ['page_id' => $this->pageId]);
    }

    /** TC-170.6 草稿页面：作者本人可以访问 */
    public function testGetPageDraftIsAuthor(): void
    {
        $this->setupWriteToken($this->uid);
        $this->insertPage($this->pageId, $this->itemId, '我的草稿', 'draft by me', 0, $this->uid, 'testuser', 1);

        $result = $this->handler->execute('get_page', ['page_id' => $this->pageId]);

        $this->assertEquals($this->pageId, $result['page_id']);
        $this->assertEquals('我的草稿', $result['page_title']);
    }

    /** TC-170.7 content_hash 正确计算 */
    public function testGetPageContentHash(): void
    {
        $this->setupWriteToken();
        $content = 'test content for hash';
        $this->insertPage($this->pageId, $this->itemId, 'Hash测试', $content);

        $result = $this->handler->execute('get_page', ['page_id' => $this->pageId]);

        $expectedHash = substr(md5($content), 0, 12);
        $this->assertEquals($expectedHash, $result['content_hash']);
    }

    /** TC-170.8 page_url 格式正确 */
    public function testGetPageUrlFormat(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, 'URL测试', 'url test');

        $result = $this->handler->execute('get_page', ['page_id' => $this->pageId]);

        $this->assertStringContainsString("/{$this->itemId}/{$this->pageId}", $result['page_url']);
    }

    /** TC-170.9 缺少查找参数报错 */
    public function testGetPageMissingParams(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('page_id');
        $this->handler->execute('get_page', []);
    }

    /** TC-170.10 unique_key 不存在报错 */
    public function testGetPageUniqueKeyNotFound(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('unique_key');
        $this->handler->execute('get_page', ['unique_key' => 'nonexistent_key']);
    }

    /** TC-170.11 通过 item_id + page_title 查找不存在的标题 */
    public function testGetPageByTitleNotFound(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面不存在');
        $this->handler->execute('get_page', [
            'item_id' => $this->itemId, 'page_title' => '不存在的标题',
        ]);
    }

    // ==================================================================
    //  TC-171  create_page
    // ==================================================================

    /** TC-171.1 正常创建页面 */
    public function testCreatePageSuccess(): void
    {
        $this->setupWriteToken();
        $this->insertItem(2, 'Proj2', $this->uid);

        $result = $this->handler->execute('create_page', [
            'item_id' => 2, 'page_title' => '新建页面', 'page_content' => '这是新页面的内容',
        ]);

        $this->assertGreaterThan(0, $result['page_id']);
        $this->assertEquals('新建页面', $result['page_title']);
        $this->assertEquals(2, $result['item_id']);
        $this->assertStringContainsString('成功', $result['message']);
    }

    /** TC-171.2 缺少 item_id 报错 */
    public function testCreatePageMissingItemId(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('项目ID不能为空');
        $this->handler->execute('create_page', [
            'page_title' => '测试', 'page_content' => '内容',
        ]);
    }

    /** TC-171.3 缺少 page_title 报错 */
    public function testCreatePageMissingTitle(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面标题不能为空');
        $this->handler->execute('create_page', [
            'item_id' => 1, 'page_content' => '内容',
        ]);
    }

    /** TC-171.4 空内容禁止保存 */
    public function testCreatePageEmptyContent(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('空内容');
        $this->handler->execute('create_page', [
            'item_id' => 1, 'page_title' => '空内容测试', 'page_content' => '',
        ]);
    }

    /**
     * TC-171.5 在不存在的项目中创建报错
     *
     * 注意：开源版 createPage 在 Item::findById 之前先调用 requireWritePermission，
     * 非存在项目会先抛 NOT_ITEM_MEMBER（“您不是该项目的成员”），
     * 因此这里只断言会抛 McpException。
     */
    public function testCreatePageItemNotFound(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->handler->execute('create_page', [
            'item_id' => 99999, 'page_title' => '测试', 'page_content' => 'content',
        ]);
    }

    /** TC-171.6 重复标题报错 */
    public function testCreatePageDuplicateTitle(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '重复标题', 'existing content');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面标题已存在');
        $this->handler->execute('create_page', [
            'item_id' => $this->itemId, 'page_title' => '重复标题', 'page_content' => 'new content',
        ]);
    }

    /** TC-171.7 带目录创建 */
    public function testCreatePageWithCatalog(): void
    {
        $this->setupWriteToken();

        $result = $this->handler->execute('create_page', [
            'item_id' => $this->itemId, 'page_title' => '有目录的页面',
            'page_content' => '带目录的内容', 'cat_name' => '技术文档/API',
        ]);

        $this->assertGreaterThan(0, $result['page_id']);
        $this->assertGreaterThan(0, $result['cat_id']);
    }

    // ==================================================================
    //  TC-172  update_page（content_hash 乐观锁）
    // ==================================================================

    /** TC-172.1 正常更新页面内容 */
    public function testUpdatePageSuccess(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '更新测试', 'old content');

        $result = $this->handler->execute('update_page', [
            'page_id' => $this->pageId, 'page_content' => 'new updated content',
        ]);

        $this->assertEquals($this->pageId, $result['page_id']);
        $this->assertStringContainsString('成功', $result['message']);
        $this->assertArrayHasKey('content_hash', $result);
    }

    /** TC-172.2 乐观锁：正确 hash 更新成功 */
    public function testUpdatePageOptimisticLockSuccess(): void
    {
        $this->setupWriteToken();
        $content = 'lock test content';
        $this->insertPage($this->pageId, $this->itemId, '乐观锁测试', $content);

        $page = $this->handler->execute('get_page', ['page_id' => $this->pageId]);
        $hash = $page['content_hash'];

        $result = $this->handler->execute('update_page', [
            'page_id' => $this->pageId, 'page_content' => 'updated lock content',
            'expected_hash' => $hash,
        ]);

        $this->assertStringContainsString('成功', $result['message']);
    }

    /** TC-172.3 乐观锁：hash 不匹配报 VERSION_CONFLICT */
    public function testUpdatePageOptimisticLockConflict(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '冲突测试', 'original content');

        $this->expectException(McpException::class);
        $this->handler->execute('update_page', [
            'page_id' => $this->pageId, 'page_content' => 'conflict update',
            'expected_hash' => 'wrong_hash_value',
        ]);
    }

    /** TC-172.4 更新页面标题 */
    public function testUpdatePageTitle(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '原标题', 'content');

        $result = $this->handler->execute('update_page', [
            'page_id' => $this->pageId, 'page_title' => '新标题',
        ]);

        $this->assertStringContainsString('成功', $result['message']);
    }

    /** TC-172.5 更新标题与其他页面重复报错 */
    public function testUpdatePageDuplicateTitle(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '原页面', 'content');
        $this->insertPage(201, $this->itemId, '已存在标题', 'other content');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面标题已存在');
        $this->handler->execute('update_page', [
            'page_id' => $this->pageId, 'page_title' => '已存在标题',
        ]);
    }

    /** TC-172.6 缺少 page_id 报错 */
    public function testUpdatePageMissingPageId(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面ID不能为空');
        $this->handler->execute('update_page', ['page_content' => 'x']);
    }

    /** TC-172.7 更新空内容报错 */
    public function testUpdatePageEmptyContent(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '测试', 'content');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('空内容');
        $this->handler->execute('update_page', [
            'page_id' => $this->pageId, 'page_content' => '',
        ]);
    }

    /** TC-172.8 没有可更新内容报错 */
    public function testUpdatePageNothingToUpdate(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '测试', 'content');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('没有需要更新的内容');
        $this->handler->execute('update_page', ['page_id' => $this->pageId]);
    }

    // ==================================================================
    //  TC-173  upsert_page
    // ==================================================================

    /** TC-173.1 页面不存在时创建 */
    public function testUpsertPageCreate(): void
    {
        $this->setupWriteToken();

        $result = $this->handler->execute('upsert_page', [
            'item_id' => $this->itemId, 'page_title' => '全新页面',
            'page_content' => 'upsert create content',
        ]);

        $this->assertGreaterThan(0, $result['page_id']);
        $this->assertStringContainsString('成功', $result['message']);
    }

    /** TC-173.2 页面已存在时更新 */
    public function testUpsertPageUpdate(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '已有页面', 'old content');

        $result = $this->handler->execute('upsert_page', [
            'item_id' => $this->itemId, 'page_title' => '已有页面',
            'page_content' => 'upsert updated content',
        ]);

        $this->assertStringContainsString('成功', $result['message']);
    }

    /**
     * TC-173.3 传入 cat_name 时更新已有页面并移动到新目录（开源版行为）
     *
     * 注意：开源版 upsertPage 当传入 cat_name 时，按 item_id + page_title 查找
     * （忽略 cat_id），命中则更新并移动目录，而非新建。
     */
    public function testUpsertPageDifferentCatalog(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '同名页面', 'content', 0);

        // 传入 cat_name：开源版会更新已有页面并移动到新目录
        $result = $this->handler->execute('upsert_page', [
            'item_id' => $this->itemId, 'page_title' => '同名页面',
            'page_content' => 'moved to different catalog', 'cat_name' => '其他目录',
        ]);

        $this->assertEquals($this->pageId, $result['page_id'], '应更新已有页面而非新建');
    }

    /** TC-173.4 缺少 item_id 报错 */
    public function testUpsertPageMissingItemId(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('项目ID不能为空');
        $this->handler->execute('upsert_page', [
            'page_title' => '测试', 'page_content' => '内容',
        ]);
    }

    /** TC-173.5 缺少 page_title 报错 */
    public function testUpsertPageMissingTitle(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面标题不能为空');
        $this->handler->execute('upsert_page', [
            'item_id' => 1, 'page_content' => '内容',
        ]);
    }

    // ==================================================================
    //  TC-174  batch_get_pages
    // ==================================================================

    /** TC-174.1 批量获取多个存在页面 */
    public function testBatchGetPagesSuccess(): void
    {
        $this->setupWriteToken();
        $this->insertPage(200, $this->itemId, '页面A', 'content A');
        $this->insertPage(201, $this->itemId, '页面B', 'content B');

        $result = $this->handler->execute('batch_get_pages', [
            'page_ids' => [200, 201],
        ]);

        $this->assertCount(2, $result['pages']);
        $this->assertEquals(2, $result['total']);
        foreach ($result['pages'] as $p) {
            $this->assertEquals('success', $p['status']);
            $this->assertArrayHasKey('data', $p);
        }
    }

    /** TC-174.2 批量获取中部分页面不存在 */
    public function testBatchGetPagesPartialFail(): void
    {
        $this->setupWriteToken();
        $this->insertPage(200, $this->itemId, '存在页面', 'content');

        $result = $this->handler->execute('batch_get_pages', [
            'page_ids' => [200, 99999],
        ]);

        $this->assertCount(2, $result['pages']);
        $this->assertEquals('success', $result['pages'][0]['status']);
        $this->assertEquals('failed', $result['pages'][1]['status']);
    }

    /** TC-174.3 page_ids 为空报错 */
    public function testBatchGetPagesEmptyIds(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('page_ids');
        $this->handler->execute('batch_get_pages', ['page_ids' => []]);
    }

    /** TC-174.4 page_ids 超过 10 个被截断 */
    public function testBatchGetPagesTruncatedTo10(): void
    {
        $this->setupWriteToken();
        $ids = [];
        for ($i = 1; $i <= 15; $i++) {
            $ids[] = 90000 + $i; // 都不存在的 page_id
        }

        $result = $this->handler->execute('batch_get_pages', ['page_ids' => $ids]);

        $this->assertCount(10, $result['pages']);
    }

    // ==================================================================
    //  TC-175  delete_page
    // ==================================================================

    /** TC-175.1 正常删除页面（软删除） */
    public function testDeletePageSuccess(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '待删除', 'content');

        $result = $this->handler->execute('delete_page', ['page_id' => $this->pageId]);

        $this->assertEquals($this->pageId, $result['page_id']);
        $this->assertStringContainsString('删除', $result['message']);
    }

    /** TC-175.2 删除后页面不可访问 */
    public function testDeletePageThenGetFails(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '删除后访问', 'content');

        $this->handler->execute('delete_page', ['page_id' => $this->pageId]);

        // 删除后 is_del=1，findByIdWithContent 过滤 is_del=0 导致查不到
        $this->expectException(McpException::class);
        $this->handler->execute('get_page', ['page_id' => $this->pageId]);
    }

    /** TC-175.3 删除不存在的页面报错 */
    public function testDeletePageNotFound(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面不存在');
        $this->handler->execute('delete_page', ['page_id' => 99999]);
    }

    /** TC-175.4 缺少 page_id 报错 */
    public function testDeletePageMissingPageId(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面ID不能为空');
        $this->handler->execute('delete_page', []);
    }

    /** TC-175.5 editor 成员不可删除页面（requireManagePermission 仅 owner/admin，验证 S1/S4 修复） */
    public function testDeletePageDeniedForEditor(): void
    {
        // uid=101 作为 editor 成员（member_group_id=1）：可编辑但不可删除
        $this->insertUser(101, 'editor');
        DB::table('item_member')->insert([
            'item_id' => $this->itemId, 'uid' => 101, 'member_group_id' => 1,
        ]);
        $this->setupWriteToken(101);
        $this->insertPage($this->pageId, $this->itemId, '页面', 'content');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('权限不足');
        $this->handler->execute('delete_page', ['page_id' => $this->pageId]);
    }

    /** TC-175.6 readonly 成员不可创建页面（requireWritePermission 需要 editor 及以上） */
    public function testCreatePageDeniedForReadonlyMember(): void
    {
        // uid=102 作为 readonly 成员（member_group_id=3）：无编辑权限
        $this->insertUser(102, 'readonly');
        DB::table('item_member')->insert([
            'item_id' => $this->itemId, 'uid' => 102, 'member_group_id' => 3,
        ]);
        $this->setupWriteToken(102);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('权限不足');
        $this->handler->execute('create_page', [
            'item_id' => $this->itemId,
            'page_title' => '禁止创建',
            'page_content' => '内容',
        ]);
    }

    /** TC-175.7 非项目成员不可读取页面（requireReadPermission → NOT_ITEM_MEMBER） */
    public function testGetPageDeniedForNonMember(): void
    {
        // uid=103 非项目成员
        $this->insertUser(103, 'outsider');
        $this->insertPage($this->pageId, $this->itemId, '页面', 'content');
        $this->handler->setTokenInfo([
            'uid'        => 103,
            'permission' => 'read',
            'scope'      => 'all',
            'token_type' => 'user_token',
        ]);

        $this->expectException(McpException::class);
        $this->handler->execute('get_page', ['page_id' => $this->pageId]);
    }

    // ==================================================================
    //  TC-176  get_page_template
    // ==================================================================

    /** TC-176.1 默认返回 api 模板 */
    public function testGetPageTemplateDefaultApi(): void
    {
        $this->setupWriteToken();

        $result = $this->handler->execute('get_page_template', []);

        $this->assertEquals('api', $result['type']);
        $this->assertNotEmpty($result['template']);
        $this->assertStringContainsString('接口名称', $result['template']);
    }

    /** TC-176.2 api 模板 */
    public function testGetPageTemplateApi(): void
    {
        $this->setupWriteToken();

        $result = $this->handler->execute('get_page_template', ['type' => 'api']);

        $this->assertEquals('api', $result['type']);
        $this->assertStringContainsString('请求方式', $result['template']);
    }

    /** TC-176.3 runapi_comment 模板 */
    public function testGetPageTemplateRunapiComment(): void
    {
        $this->setupWriteToken();

        $result = $this->handler->execute('get_page_template', ['type' => 'runapi_comment']);

        $this->assertEquals('runapi_comment', $result['type']);
        $this->assertStringContainsString('showdoc', $result['template']);
    }

    /** TC-176.4 database 模板 */
    public function testGetPageTemplateDatabase(): void
    {
        $this->setupWriteToken();

        $result = $this->handler->execute('get_page_template', ['type' => 'database']);

        $this->assertEquals('database', $result['type']);
        $this->assertStringContainsString('字段列表', $result['template']);
    }

    /** TC-176.5 general 模板 */
    public function testGetPageTemplateGeneral(): void
    {
        $this->setupWriteToken();

        $result = $this->handler->execute('get_page_template', ['type' => 'general']);

        $this->assertEquals('general', $result['type']);
        $this->assertStringContainsString('概述', $result['template']);
    }

    /** TC-176.6 未知类型回退到 api 模板 */
    public function testGetPageTemplateUnknownTypeFallsBack(): void
    {
        $this->setupWriteToken();

        $result = $this->handler->execute('get_page_template', ['type' => 'nonexistent']);

        // 未知类型回退到 api 模板
        $this->assertEquals('nonexistent', $result['type']);
        $this->assertStringContainsString('接口名称', $result['template']);
    }

    // ==================================================================
    //  TC-177  get_page_history / get_page_version
    // ==================================================================

    /** TC-177.1 获取页面历史列表 */
    public function testGetPageHistory(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '历史测试', 'v1 content');

        // 插入历史记录（addtime 随版本递增，保证 desc 排序确定）
        $this->insertPageHistory($this->pageId, 1, '历史版本1', 'version 1 content');
        $this->insertPageHistory($this->pageId, 2, '历史版本2', 'version 2 content');

        $result = $this->handler->execute('get_page_history', ['page_id' => $this->pageId]);

        $this->assertEquals($this->pageId, $result['page_id']);
        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['history']);
        // 历史按 addtime desc 排序
        $this->assertEquals(2, $result['history'][0]['version_id']);
        $this->assertEquals(1, $result['history'][1]['version_id']);
    }

    /** TC-177.2 获取页面历史：无历史返回空 */
    public function testGetPageHistoryEmpty(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '无历史', 'content');

        $result = $this->handler->execute('get_page_history', ['page_id' => $this->pageId]);

        $this->assertEquals(0, $result['total']);
        $this->assertCount(0, $result['history']);
    }

    /** TC-177.3 缺少 page_id 报错 */
    public function testGetPageHistoryMissingPageId(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面ID不能为空');
        $this->handler->execute('get_page_history', []);
    }

    /** TC-177.4 获取指定版本内容 */
    public function testGetPageVersion(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '版本测试', 'current');
        $this->insertPageHistory($this->pageId, 1, '版本1', 'old content from version 1');

        $result = $this->handler->execute('get_page_version', [
            'page_id' => $this->pageId, 'version_id' => 1,
        ]);

        $this->assertEquals($this->pageId, $result['page_id']);
        $this->assertEquals(1, $result['version_id']);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('page_title', $result);
    }

    /** TC-177.5 获取不存在的版本报错 */
    public function testGetPageVersionNotFound(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '测试', 'content');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('历史版本不存在');
        $this->handler->execute('get_page_version', [
            'page_id' => $this->pageId, 'version_id' => 99999,
        ]);
    }

    /** TC-177.6 缺少 version_id 报错 */
    public function testGetPageVersionMissingVersionId(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '测试', 'content');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('版本ID不能为空');
        $this->handler->execute('get_page_version', ['page_id' => $this->pageId]);
    }

    // ==================================================================
    //  TC-178  diff_page_versions / restore_page_version
    // ==================================================================

    /** TC-178.1 对比两个版本的差异 */
    public function testDiffPageVersions(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, 'Diff测试', 'current');
        $this->insertPageHistory($this->pageId, 1, '版本1', "line1\nline2\nline3");
        $this->insertPageHistory($this->pageId, 2, '版本2', "line1\nchanged\nline3");

        $result = $this->handler->execute('diff_page_versions', [
            'page_id' => $this->pageId, 'version_id_1' => 1, 'version_id_2' => 2,
        ]);

        $this->assertEquals($this->pageId, $result['page_id']);
        $this->assertArrayHasKey('diff', $result);
        $this->assertArrayHasKey('changes', $result['diff']);
        $this->assertArrayHasKey('summary', $result['diff']);
        $this->assertArrayHasKey('added', $result['diff']['summary']);
        $this->assertArrayHasKey('removed', $result['diff']['summary']);
    }

    /** TC-178.2 diff：两个相同版本无变化 */
    public function testDiffPageVersionsIdentical(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, 'Diff测试', 'current');
        $content = "same\ncontent\nhere";
        $this->insertPageHistory($this->pageId, 1, '版本1', $content);
        $this->insertPageHistory($this->pageId, 2, '版本2', $content);

        $result = $this->handler->execute('diff_page_versions', [
            'page_id' => $this->pageId, 'version_id_1' => 1, 'version_id_2' => 2,
        ]);

        $this->assertCount(0, $result['diff']['changes']);
        $this->assertEquals(0, $result['diff']['summary']['added']);
        $this->assertEquals(0, $result['diff']['summary']['removed']);
    }

    /** TC-178.3 diff：版本不存在报错 */
    public function testDiffPageVersionsVersionNotFound(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, 'Diff测试', 'current');
        $this->insertPageHistory($this->pageId, 1, '版本1', 'content');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('版本');
        $this->handler->execute('diff_page_versions', [
            'page_id' => $this->pageId, 'version_id_1' => 1, 'version_id_2' => 99999,
        ]);
    }

    /** TC-178.4 恢复页面到指定版本 */
    public function testRestorePageVersion(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '恢复测试', 'current content');
        $this->insertPageHistory($this->pageId, 1, '历史版本', 'historical content');

        $result = $this->handler->execute('restore_page_version', [
            'page_id' => $this->pageId, 'version_id' => 1,
        ]);

        $this->assertEquals($this->pageId, $result['page_id']);
        $this->assertEquals(1, $result['version_id']);
        $this->assertStringContainsString('恢复', $result['message']);
        $this->assertArrayHasKey('restored_at', $result);
    }

    /** TC-178.5 恢复不存在的版本报错 */
    public function testRestorePageVersionNotFound(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '测试', 'content');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('历史版本不存在');
        $this->handler->execute('restore_page_version', [
            'page_id' => $this->pageId, 'version_id' => 99999,
        ]);
    }

    /** TC-178.6 恢复后当前版本被自动备份到历史 */
    public function testRestorePageVersionAutoBackup(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '备份测试', 'original content');
        $this->insertPageHistory($this->pageId, 1, '目标版本', 'old version');

        // 恢复
        $this->handler->execute('restore_page_version', [
            'page_id' => $this->pageId, 'version_id' => 1,
        ]);

        // 检查历史中多了一条备份记录
        $historyResult = $this->handler->execute('get_page_history', ['page_id' => $this->pageId]);
        $this->assertGreaterThanOrEqual(2, $historyResult['total']);

        // 找到备份记录（change_summary 含 "恢复前自动备份"）
        $backupFound = false;
        foreach ($historyResult['history'] as $h) {
            if (($h['change_summary'] ?? '') === '恢复前自动备份') {
                $backupFound = true;
                break;
            }
        }
        $this->assertTrue($backupFound, '应有一条"恢复前自动备份"的历史记录');
    }

    /** TC-178.7 diff 缺少 page_id 报错 */
    public function testDiffPageVersionsMissingPageId(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面ID不能为空');
        $this->handler->execute('diff_page_versions', [
            'version_id_1' => 1, 'version_id_2' => 2,
        ]);
    }

    /** TC-178.8 restore 缺少 version_id 报错 */
    public function testRestorePageVersionMissingVersionId(): void
    {
        $this->setupWriteToken();
        $this->insertPage($this->pageId, $this->itemId, '测试', 'content');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('版本ID不能为空');
        $this->handler->execute('restore_page_version', ['page_id' => $this->pageId]);
    }
}
