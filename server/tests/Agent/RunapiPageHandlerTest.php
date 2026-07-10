<?php

namespace Tests\Agent;

use PHPUnit\Framework\TestCase;
use App\Mcp\Handler\RunapiPageHandler;
use App\Mcp\McpException;
use App\Mcp\McpError;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * RunapiPageHandler 单元测试（开源版）
 *
 * 直接实例化 RunapiPageHandler，通过 execute() 调用操作，
 * 使用 SQLite 内存库验证 RunApi 页面操作。
 *
 * 与主版的差异（已裁剪/调整）：
 *   - page 改为单表存储：insertRunapiPage 只写 DB::table('page')（含 page_content 等全部列）。
 *   - page_history 改为单表：insertPageHistory 只写 DB::table('page_history')。
 *   - page_content 不压缩：直接存原始字符串。
 *
 * 覆盖用例：
 *   TC-RA-1   get_runapi_page 基本读取
 *   TC-RA-2   create_runapi_page 基本创建
 *   TC-RA-3   create_runapi_page 通过 cat_id 指定目录
 *   TC-RA-4   update_runapi_page 通过 cat_id 移动目录
 *   TC-RA-5   upsert_runapi_page 通过 cat_id 创建/更新
 *   TC-RA-6   cat_id 跨项目校验
 */
class RunapiPageHandlerTest extends TestCase
{
    /** @var RunapiPageHandler */
    private RunapiPageHandler $handler;

    /** @var int 测试用户 UID */
    private int $uid = 100;

    /** @var int 测试项目 ID（RunApi 项目，item_type=3） */
    private int $itemId = 3;

    /** @var int 测试页面 ID */
    private int $pageId = 300;

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

    private function insertUser(int $uid, string $username, int $groupid = 0): void
    {
        DB::table('user')->insert([
            'uid' => $uid, 'username' => $username, 'groupid' => $groupid,
        ]);
    }

    private function insertItem(int $itemId, string $name, int $uid = 100, int $itemType = 3): void
    {
        DB::table('item')->insert([
            'item_id' => $itemId, 'item_name' => $name,
            'uid' => $uid, 'item_type' => $itemType, 'is_del' => 0,
        ]);
    }

    private function insertCatalog(int $catId, int $itemId, string $catName, int $parentCatId = 0, int $level = 2): int
    {
        DB::table('catalog')->insert([
            'cat_id'       => $catId,
            'item_id'      => $itemId,
            'cat_name'     => $catName,
            'parent_cat_id'=> $parentCatId,
            's_number'     => 99,
            'addtime'      => time(),
            'level'        => $level,
        ]);
        return $catId;
    }

    /**
     * 插入测试页面到单表 page（开源版不压缩、不分表）
     */
    private function insertRunapiPage(
        int $pageId,
        int $itemId,
        string $title,
        string $content = '',
        int $catId = 0,
        int $authorUid = 100,
        string $authorUsername = 'testuser'
    ): void {
        $now = time();

        DB::table('page')->insert([
            'page_id'         => $pageId,
            'item_id'         => $itemId,
            'cat_id'          => $catId,
            'page_title'      => $title,
            'page_content'    => $content,
            'is_del'          => 0,
            'is_draft'        => 0,
            's_number'        => 99,
            'addtime'         => $now,
            'author_uid'      => $authorUid,
            'author_username' => $authorUsername,
        ]);
    }

    /**
     * 生成最小的合法 RunApi JSON 内容
     */
    private function validRunapiContent(string $url = '/api/test', string $method = 'post'): array
    {
        return [
            'info' => [
                'url'    => $url,
                'method' => $method,
                'name'   => '测试接口',
            ],
            'request' => [
                'params'  => [],
                'headers' => [],
                'query'   => [],
            ],
            'response' => [],
        ];
    }

    // ------------------------------------------------------------------
    //  setUp / tearDown
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->handler = new RunapiPageHandler();
        $this->clearFileCache();

        $coreTables = ['item', 'page', 'page_history', 'user', 'catalog', 'item_member',
                       'team_item_member', 'file_page', 'single_page', 'options', 'item_ai_config'];
        foreach ($coreTables as $table) {
            DB::table($table)->delete();
        }

        $this->insertUser($this->uid, 'testuser');
        $this->insertItem($this->itemId, 'RunApiProject', $this->uid, 3);
    }

    protected function tearDown(): void
    {
        $coreTables = ['item', 'page', 'page_history', 'user', 'catalog', 'item_member',
                       'team_item_member', 'file_page', 'single_page', 'options', 'item_ai_config'];
        foreach ($coreTables as $t) {
            DB::table($t)->delete();
        }
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
    //  TC-RA-1  get_runapi_page
    // ==================================================================

    /** TC-RA-1.1 通过 page_id 获取 RunApi 页面 */
    public function testGetRunapiPageByPageId(): void
    {
        $this->setupWriteToken();
        $this->insertRunapiPage($this->pageId, $this->itemId, '测试接口', '{"info":{"url":"/api/test","method":"post"}}');

        $result = $this->handler->execute('get_runapi_page', ['page_id' => $this->pageId]);

        $this->assertEquals($this->pageId, $result['page_id']);
        $this->assertEquals('测试接口', $result['page_title']);
        $this->assertEquals('runapi', $result['type']);
    }

    /** TC-RA-1.2 非 RunApi 项目报错 */
    public function testGetRunapiPageWrongItemType(): void
    {
        $this->setupWriteToken();
        $this->insertItem(4, 'NormalProject', $this->uid, 1);
        $this->insertRunapiPage($this->pageId, 4, '普通页面', 'content');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('item_type');
        $this->handler->execute('get_runapi_page', ['page_id' => $this->pageId]);
    }

    // ==================================================================
    //  TC-RA-2  create_runapi_page 基本创建
    // ==================================================================

    /** TC-RA-2.1 正常创建 RunApi 页面 */
    public function testCreateRunapiPageSuccess(): void
    {
        $this->setupWriteToken();

        $result = $this->handler->execute('create_runapi_page', [
            'item_id'      => $this->itemId,
            'page_title'   => '新建接口',
            'page_content' => $this->validRunapiContent(),
        ]);

        $this->assertGreaterThan(0, $result['page_id']);
        $this->assertStringContainsString('成功', $result['message']);
    }

    /** TC-RA-2.2 缺少 info.url 报错 */
    public function testCreateRunapiPageMissingUrl(): void
    {
        $this->setupWriteToken();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('info.url');
        $this->handler->execute('create_runapi_page', [
            'item_id'      => $this->itemId,
            'page_title'   => '无URL',
            'page_content' => ['info' => ['method' => 'post']],
        ]);
    }

    // ==================================================================
    //  TC-RA-3  create_runapi_page 通过 cat_id 指定目录
    // ==================================================================

    /** TC-RA-3.1 create_runapi_page 通过 cat_id 精确指定嵌套目录 */
    public function testCreateRunapiPageWithCatId(): void
    {
        $this->setupWriteToken();
        $this->insertCatalog(1323, $this->itemId, '用户管理', 0, 2);
        $this->insertCatalog(1311, $this->itemId, '登录/注册', 1323, 3);

        $result = $this->handler->execute('create_runapi_page', [
            'item_id'      => $this->itemId,
            'page_title'   => '登录接口',
            'page_content' => $this->validRunapiContent('/api/login'),
            'cat_id'       => 1311,
        ]);

        $this->assertGreaterThan(0, $result['page_id']);
        $this->assertEquals(1311, $result['cat_id']);
    }

    /** TC-RA-3.2 create_runapi_page cat_id 优先于 cat_name */
    public function testCreateRunapiPageCatIdOverridesCatName(): void
    {
        $this->setupWriteToken();
        $this->insertCatalog(8000, $this->itemId, 'cat_id目录', 0, 2);

        $result = $this->handler->execute('create_runapi_page', [
            'item_id'      => $this->itemId,
            'page_title'   => '优先级测试',
            'page_content' => $this->validRunapiContent(),
            'cat_id'       => 8000,
            'cat_name'     => 'cat_name目录',
        ]);

        $this->assertEquals(8000, $result['cat_id']);
    }

    /** TC-RA-3.3 create_runapi_page cat_id 不属于该项目时报错 */
    public function testCreateRunapiPageCatIdNotInItem(): void
    {
        $this->setupWriteToken();
        $this->insertItem(5, 'OtherRunApi', $this->uid, 3);
        $this->insertCatalog(8100, 5, '其他项目目录', 0, 2);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('不属于该项目');
        $this->handler->execute('create_runapi_page', [
            'item_id'      => $this->itemId,
            'page_title'   => '跨项目测试',
            'page_content' => $this->validRunapiContent(),
            'cat_id'       => 8100,
        ]);
    }

    // ==================================================================
    //  TC-RA-4  update_runapi_page 通过 cat_id 移动目录
    // ==================================================================

    /** TC-RA-4.1 update_runapi_page 通过 cat_id 移动页面 */
    public function testUpdateRunapiPageMoveByCatId(): void
    {
        $this->setupWriteToken();
        $this->insertRunapiPage($this->pageId, $this->itemId, '待移动接口', '{"info":{"url":"/api/test","method":"get"}}');
        $this->insertCatalog(8200, $this->itemId, '新目录', 0, 2);

        $result = $this->handler->execute('update_runapi_page', [
            'page_id'    => $this->pageId,
            'cat_id'     => 8200,
        ]);

        $this->assertStringContainsString('成功', $result['message']);

        // 验证页面已移动
        $page = $this->handler->execute('get_runapi_page', ['page_id' => $this->pageId]);
        $this->assertEquals(8200, $page['cat_id']);
    }

    /** TC-RA-4.2 update_runapi_page cat_id 优先于 cat_name */
    public function testUpdateRunapiPageCatIdOverridesCatName(): void
    {
        $this->setupWriteToken();
        $this->insertRunapiPage($this->pageId, $this->itemId, '优先级测试', '{"info":{"url":"/api/test","method":"get"}}');
        $this->insertCatalog(8300, $this->itemId, 'cat_id目录', 0, 2);

        $result = $this->handler->execute('update_runapi_page', [
            'page_id'  => $this->pageId,
            'cat_id'   => 8300,
            'cat_name' => 'cat_name目录',
        ]);

        $this->assertStringContainsString('成功', $result['message']);

        $page = $this->handler->execute('get_runapi_page', ['page_id' => $this->pageId]);
        $this->assertEquals(8300, $page['cat_id']);
    }

    /** TC-RA-4.3 update_runapi_page cat_id 不属于该项目时报错 */
    public function testUpdateRunapiPageCatIdNotInItem(): void
    {
        $this->setupWriteToken();
        $this->insertRunapiPage($this->pageId, $this->itemId, '测试接口', '{"info":{"url":"/api/test","method":"get"}}');
        $this->insertItem(6, 'OtherRunApi2', $this->uid, 3);
        $this->insertCatalog(8400, 6, '其他项目目录', 0, 2);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('不属于该项目');
        $this->handler->execute('update_runapi_page', [
            'page_id' => $this->pageId,
            'cat_id'  => 8400,
        ]);
    }

    /** TC-RA-4.4 update_runapi_page 移动到目标目录时，目标目录已有同名页面则报错 */
    public function testUpdateRunapiPageMoveToCatalogWithDuplicateTitle(): void
    {
        $this->setupWriteToken();
        $this->insertCatalog(8450, $this->itemId, '源目录', 0, 2);
        $this->insertCatalog(8451, $this->itemId, '目标目录', 0, 2);
        // 页面A在源目录
        $this->insertRunapiPage(300, $this->itemId, '同名接口', '{"info":{"url":"/api/a","method":"get"}}', 8450);
        // 页面B在目标目录（同名）
        $this->insertRunapiPage(301, $this->itemId, '同名接口', '{"info":{"url":"/api/b","method":"get"}}', 8451);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('目标目录下已存在同名页面');
        $this->handler->execute('update_runapi_page', [
            'page_id' => 300,
            'cat_id'  => 8451,
        ]);
    }

    // ==================================================================
    //  TC-RA-5  upsert_runapi_page 通过 cat_id 创建/更新
    // ==================================================================

    /** TC-RA-5.1 upsert_runapi_page 通过 cat_id 创建新接口 */
    public function testUpsertRunapiPageCreateWithCatId(): void
    {
        $this->setupWriteToken();
        $this->insertCatalog(8500, $this->itemId, 'upsert目录', 0, 2);

        $result = $this->handler->execute('upsert_runapi_page', [
            'item_id'      => $this->itemId,
            'page_title'   => 'upsert新建接口',
            'page_content' => $this->validRunapiContent(),
            'cat_id'       => 8500,
        ]);

        $this->assertGreaterThan(0, $result['page_id']);
        $this->assertStringContainsString('成功', $result['message']);
    }

    /** TC-RA-5.2 upsert_runapi_page 通过 cat_id 更新已有接口 */
    public function testUpsertRunapiPageUpdateWithCatId(): void
    {
        $this->setupWriteToken();
        $this->insertCatalog(8600, $this->itemId, '原目录', 0, 2);
        $existingContent = json_encode([
            'info' => ['url' => '/api/old', 'method' => 'get', 'name' => '已有接口'],
            'request' => ['params' => [], 'headers' => [], 'query' => []],
            'response' => [],
        ]);
        $this->insertRunapiPage($this->pageId, $this->itemId, '已有接口', $existingContent, 8600);

        $this->insertCatalog(8601, $this->itemId, '新目录', 0, 2);
        $result = $this->handler->execute('upsert_runapi_page', [
            'item_id'      => $this->itemId,
            'page_title'   => '已有接口',
            'page_content' => $this->validRunapiContent('/api/new'),
            'cat_id'       => 8601,
        ]);

        $this->assertStringContainsString('成功', $result['message']);
    }

    /** TC-RA-5.3 upsert_runapi_page cat_id 不属于该项目时报错 */
    public function testUpsertRunapiPageCatIdNotInItem(): void
    {
        $this->setupWriteToken();
        $this->insertItem(7, 'OtherRunApi3', $this->uid, 3);
        $this->insertCatalog(8700, 7, '其他目录', 0, 2);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('不属于该项目');
        $this->handler->execute('upsert_runapi_page', [
            'item_id'      => $this->itemId,
            'page_title'   => '跨项目测试',
            'page_content' => $this->validRunapiContent(),
            'cat_id'       => 8700,
        ]);
    }
}
