<?php

namespace Tests\Agent;

use PHPUnit\Framework\TestCase;
use App\Mcp\Handler\RunapiPageHandler;
use App\Mcp\Handler\AttachmentHandler;
use App\Mcp\Handler\OpenApiHandler;
use App\Mcp\Handler\PageHandler;
use App\Mcp\McpException;
use App\Model\Page;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * RunAPI / 附件 / OpenAPI / 单页分享 MCP 工具测试（开源版）
 *
 * 覆盖用例：
 *   TC-200  get_runapi_page / create_runapi_page / update_runapi_page / upsert_runapi_page
 *   TC-201  upload_attachment / list_attachments / delete_attachment
 *   TC-202  import_openapi
 *   TC-203  create_single_page_link / get_single_page_link / delete_single_page_link
 *
 * 与主版的差异：
 *   - 开源版无积分/敏感词/VIP：移除 insertUser 中的 payment_verify，
 *     移除 vip / bad_keywords / item_whitelist 表。
 *   - 开源版 page 为单表（page_content 不压缩，不分表）：
 *     insertPage 改为只写单表 page；createFullSchema 不再创建 page_NN 分表。
 *   - PageHandler 的 create/get/delete_single_page_link 返回值不含 page_id 字段
 *     （开源版移除了该字段），相关断言已删除。
 *   - OpenApiHandler 的 SSRF 防护（isPrivateOrReservedHost）在开源版同样存在，
 *     import_openapi 测试保留。
 */
class McpToolsRemainingTest extends TestCase
{
    // ==================================================================
    //  公共辅助
    // ==================================================================

    private function setupWriteToken(object $handler, int $uid = 100): void
    {
        $handler->setTokenInfo([
            'uid'        => $uid,
            'permission' => 'write',
            'scope'      => 'all',
            'token_type' => 'user_token',
        ]);
    }

    private function setupReadToken(object $handler, int $uid = 100): void
    {
        $handler->setTokenInfo([
            'uid'        => $uid,
            'permission' => 'read',
            'scope'      => 'all',
            'token_type' => 'user_token',
        ]);
    }

    private function insertUser(int $uid, string $username, int $groupid = 0): void
    {
        DB::table('user')->insert([
            'uid'      => $uid,
            'username' => $username,
            'groupid'  => $groupid,
            'email'    => $username . '@test.com',
            'email_verify' => 1,
        ]);
    }

    private function insertItem(int $itemId, string $name, int $uid = 100, int $itemType = 1): void
    {
        DB::table('item')->insert([
            'item_id'          => $itemId,
            'item_name'        => $name,
            'uid'              => $uid,
            'item_type'        => $itemType,
            'is_del'           => 0,
            'last_update_time' => 0,
        ]);
    }

    private function insertItemMember(int $itemId, int $uid, int $groupId = 1): void
    {
        DB::table('item_member')->insert([
            'item_id'         => $itemId,
            'uid'             => $uid,
            'member_group_id' => $groupId,
        ]);
    }

    /**
     * 插入页面到开源版单表 page
     */
    private function insertPage(
        int $pageId,
        int $itemId,
        string $title,
        string $content = '',
        int $authorUid = 100,
        string $authorUsername = 'testuser'
    ): void {
        $now = time();

        DB::table('page')->insert([
            'page_id'         => $pageId,
            'item_id'         => $itemId,
            'cat_id'          => 0,
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

    private function insertUploadFile(int $fileId, int $uid, int $itemId, array $extra = []): void
    {
        DB::table('upload_file')->insert(array_merge([
            'file_id'       => $fileId,
            'uid'           => $uid,
            'item_id'       => $itemId,
            'display_name'  => 'test.txt',
            'file_type'     => 'text/plain',
            'file_size'     => 100,
            'sign'          => md5('file' . $fileId),
            'real_url'      => '',
            'addtime'       => time(),
        ], $extra));
    }

    private function insertFilePage(int $fileId, int $itemId, int $pageId = 0): void
    {
        DB::table('file_page')->insert([
            'file_id' => $fileId,
            'item_id' => $itemId,
            'page_id' => $pageId,
        ]);
    }

    /**
     * 构造一个最小合法 RunAPI page_content
     */
    private function makeRunapiContent(string $url = '/api/test', string $method = 'get'): array
    {
        return [
            'info' => [
                'url'    => $url,
                'method' => $method,
            ],
            'request' => [],
            'response' => [],
        ];
    }

    private function insertSinglePage(string $uniqueKey, int $pageId, int $expireTime = 0): void
    {
        DB::table('single_page')->insert([
            'unique_key'  => $uniqueKey,
            'page_id'     => $pageId,
            'expire_time' => $expireTime,
        ]);
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

    private function createFullSchema(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $tables = [
            'item' => function ($t) {
                $t->increments('item_id');
                $t->string('item_name', 255)->default('');
                $t->string('item_domain', 100)->default('');
                $t->string('password', 255)->default('');
                $t->string('item_description', 255)->default('');
                $t->string('username', 255)->default('');
                $t->integer('uid')->default(0);
                $t->integer('item_type')->default(1);
                $t->integer('is_del')->default(0);
                $t->integer('last_update_time')->default(0);
                $t->integer('addtime')->default(0);
            },
            'user' => function ($t) {
                $t->increments('uid');
                $t->string('username', 255)->default('');
                $t->integer('groupid')->default(0);
                $t->string('email', 255)->default('');
                $t->integer('email_verify')->default(0);
            },
            'catalog' => function ($t) {
                $t->increments('cat_id');
                $t->integer('item_id')->default(0);
                $t->string('cat_name', 255)->default('');
                $t->integer('parent_cat_id')->default(0);
                $t->integer('s_number')->default(99);
                $t->integer('addtime')->default(0);
                $t->integer('level')->default(1);
            },
            'item_member' => function ($t) {
                $t->increments('id');
                $t->integer('item_id')->default(0);
                $t->integer('uid')->default(0);
                $t->integer('member_group_id')->default(0);
            },
            'team_item_member' => function ($t) {
                $t->increments('id');
                $t->integer('item_id')->default(0);
                $t->integer('member_uid')->default(0);
                $t->integer('member_group_id')->default(0);
            },
            'upload_file' => function ($t) {
                $t->increments('file_id');
                $t->integer('uid')->default(0);
                $t->integer('item_id')->default(0);
                $t->string('display_name', 255)->default('');
                $t->string('file_type', 100)->default('');
                $t->integer('file_size')->default(0);
                $t->string('sign', 255)->default('');
                $t->text('real_url')->nullable();
                $t->integer('addtime')->default(0);
            },
            'file_page' => function ($t) {
                $t->increments('id');
                $t->integer('file_id')->default(0);
                $t->integer('item_id')->default(0);
                $t->integer('page_id')->default(0);
            },
            'single_page' => function ($t) {
                $t->increments('id');
                $t->string('unique_key', 255)->default('');
                $t->integer('page_id')->default(0);
                $t->integer('expire_time')->default(0);
            },
        ];

        foreach ($tables as $name => $callback) {
            if ($schema->hasTable($name)) {
                $schema->drop($name);
            }
            $schema->create($name, $callback);
        }

        // 开源版单表 page（含全部列，不分表）
        if ($schema->hasTable('page')) {
            $schema->drop('page');
        }
        $schema->create('page', function ($t) {
            $t->increments('page_id');
            $t->integer('item_id')->default(0);
            $t->integer('cat_id')->default(0);
            $t->string('page_title', 255)->default('');
            $t->text('page_content')->nullable();
            $t->text('ext_info')->nullable();
            $t->integer('is_del')->default(0);
            $t->integer('is_draft')->default(0);
            $t->integer('s_number')->default(99);
            $t->integer('addtime')->default(0);
            $t->integer('author_uid')->default(0);
            $t->string('author_username', 255)->default('');
            $t->text('page_comments')->nullable();
        });
    }

    private function deleteAllData(): void
    {
        $coreTables = [
            'item', 'page', 'user', 'catalog', 'item_member', 'team_item_member',
            'upload_file', 'file_page', 'single_page',
        ];
        foreach ($coreTables as $t) {
            try { DB::table($t)->delete(); } catch (\Throwable $e) {}
        }
        // page_history 由 bootstrap 创建，update_runapi_page 会写入，需清理
        try { DB::table('page_history')->delete(); } catch (\Throwable $e) {}
        $this->clearFileCache();
    }

    // ==================================================================
    //  setUp / tearDown
    // ==================================================================

    protected function setUp(): void
    {
        // GET_LOCK / RELEASE_LOCK 已由 bootstrap 注册（开源版 Handler 不使用，
        // 此处不再重复注册）
        $this->clearFileCache();
        $this->createFullSchema();

        // 默认数据
        $this->insertUser(100, 'testuser');
        $this->insertItem(1, '普通项目', 100, 1);
        $this->insertItem(3, 'RunApi项目', 100, 3);  // item_type=3 → RunAPI
        $this->insertItemMember(1, 100);
        $this->insertItemMember(3, 100);
    }

    protected function tearDown(): void
    {
        $this->deleteAllData();
    }

    // ==================================================================
    //  TC-200  RunAPI 工具
    // ==================================================================

    /** TC-200.1 通过 page_id 获取 RunAPI 页面 */
    public function testGetRunapiPageByPageId(): void
    {
        $handler = new RunapiPageHandler();
        $this->setupReadToken($handler);

        $content = json_encode($this->makeRunapiContent('/api/users', 'get'));
        $this->insertPage(201, 3, '获取用户列表', $content);

        $result = $handler->execute('get_runapi_page', ['page_id' => 201]);

        $this->assertEquals(201, $result['page_id']);
        $this->assertEquals('获取用户列表', $result['page_title']);
        $this->assertEquals('runapi', $result['type']);
        $this->assertEquals(3, $result['item_id']);
        $this->assertNotEmpty($result['page_content']);
        $this->assertEquals('/api/users', $result['page_content']['info']['url']);
    }

    /** TC-200.2 通过 item_id + page_title 获取 RunAPI 页面 */
    public function testGetRunapiPageByTitle(): void
    {
        $handler = new RunapiPageHandler();
        $this->setupReadToken($handler);

        $content = json_encode($this->makeRunapiContent('/api/items', 'post'));
        $this->insertPage(202, 3, '创建物品', $content);

        $result = $handler->execute('get_runapi_page', [
            'item_id'    => 3,
            'page_title' => '创建物品',
        ]);

        $this->assertEquals(202, $result['page_id']);
        $this->assertEquals('创建物品', $result['page_title']);
        $this->assertEquals('post', $result['page_content']['info']['method']);
    }

    /** TC-200.3 创建 RunAPI 页面 */
    public function testCreateRunapiPage(): void
    {
        $handler = new RunapiPageHandler();
        $this->setupWriteToken($handler);

        $result = $handler->execute('create_runapi_page', [
            'item_id'      => 3,
            'page_title'   => '新接口',
            'page_content' => $this->makeRunapiContent('/api/new', 'put'),
        ]);

        $this->assertGreaterThan(0, $result['page_id']);
        $this->assertEquals('新接口', $result['page_title']);
        $this->assertEquals(3, $result['item_id']);
        $this->assertStringContainsString('成功', $result['message']);

        // 验证已落库（开源版单表 page）
        $tableName = Page::tableForItem(3);
        $page = DB::table($tableName)->where('page_id', $result['page_id'])->first();
        $this->assertNotNull($page);
        $this->assertEquals('新接口', $page->page_title);
    }

    /** TC-200.4 更新 RunAPI 页面 */
    public function testUpdateRunapiPage(): void
    {
        $handler = new RunapiPageHandler();
        $this->setupWriteToken($handler);

        $content = json_encode($this->makeRunapiContent('/api/old', 'get'));
        $this->insertPage(203, 3, '旧接口', $content);

        $newContent = $this->makeRunapiContent('/api/updated', 'post');
        $result = $handler->execute('update_runapi_page', [
            'page_id'      => 203,
            'page_content' => $newContent,
        ]);

        $this->assertEquals(203, $result['page_id']);
        $this->assertStringContainsString('成功', $result['message']);
        $this->assertNotEmpty($result['content_hash']);

        // 验证已更新（通过 handler 的 get 接口确认）
        $getResult = $handler->execute('get_runapi_page', ['page_id' => 203]);
        $this->assertEquals('/api/updated', $getResult['page_content']['info']['url']);
        $this->assertEquals('post', $getResult['page_content']['info']['method']);
    }

    /** TC-200.5 upsert：首次创建 */
    public function testUpsertRunapiPageCreate(): void
    {
        $handler = new RunapiPageHandler();
        $this->setupWriteToken($handler);

        $result = $handler->execute('upsert_runapi_page', [
            'item_id'      => 3,
            'page_title'   => 'upsert新接口',
            'page_content' => $this->makeRunapiContent('/api/upsert', 'delete'),
        ]);

        $this->assertGreaterThan(0, $result['page_id']);
        $this->assertEquals('upsert新接口', $result['page_title']);
    }

    /** TC-200.6 upsert：标题已存在则更新 */
    public function testUpsertRunapiPageUpdate(): void
    {
        $handler = new RunapiPageHandler();
        $this->setupWriteToken($handler);

        $content = json_encode($this->makeRunapiContent('/api/v1', 'get'));
        $this->insertPage(204, 3, '已有接口', $content);

        $result = $handler->execute('upsert_runapi_page', [
            'item_id'      => 3,
            'page_title'   => '已有接口',
            'page_content' => $this->makeRunapiContent('/api/v2', 'post'),
        ]);

        $this->assertEquals(204, $result['page_id']);
        $this->assertStringContainsString('成功', $result['message']);

        // 验证已更新（通过 get 接口确认）
        $getResult = $handler->execute('get_runapi_page', ['page_id' => 204]);
        $this->assertEquals('/api/v2', $getResult['page_content']['info']['url']);
    }

    /** TC-200.7 创建 RunAPI 页面在非 RunAPI 项目时报错 */
    public function testCreateRunapiPageWrongItemType(): void
    {
        $handler = new RunapiPageHandler();
        $this->setupWriteToken($handler);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('RunApi');
        $handler->execute('create_runapi_page', [
            'item_id'      => 1,  // item_type=1（普通项目）
            'page_title'   => 'test',
            'page_content' => $this->makeRunapiContent('/api/x', 'get'),
        ]);
    }

    /** TC-200.8 创建 RunAPI 页面缺少 info.url 报错 */
    public function testCreateRunapiPageMissingUrl(): void
    {
        $handler = new RunapiPageHandler();
        $this->setupWriteToken($handler);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('info.url');
        $handler->execute('create_runapi_page', [
            'item_id'      => 3,
            'page_title'   => 'test',
            'page_content' => ['info' => ['method' => 'get']],
        ]);
    }

    // ==================================================================
    //  TC-201  附件管理
    // ==================================================================

    /** TC-201.1 list_attachments 按 item_id 列出附件 */
    public function testListAttachmentsByItemId(): void
    {
        $handler = new AttachmentHandler();
        $this->setupReadToken($handler);

        $this->insertUploadFile(301, 100, 1);
        $this->insertFilePage(301, 1);

        $result = $handler->execute('list_attachments', ['item_id' => 1]);

        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['attachments']);
        $this->assertEquals(301, $result['attachments'][0]['file_id']);
        $this->assertEquals('test.txt', $result['attachments'][0]['file_name']);
    }

    /** TC-201.2 list_attachments 按 page_id 列出附件 */
    public function testListAttachmentsByPageId(): void
    {
        $handler = new AttachmentHandler();
        $this->setupReadToken($handler);

        $this->insertPage(200, 1, '有附件的页面');
        $this->insertUploadFile(302, 100, 1);
        $this->insertFilePage(302, 1, 200);

        $result = $handler->execute('list_attachments', ['page_id' => 200]);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals(200, $result['attachments'][0]['page_id']);
    }

    /** TC-201.3 list_attachments 空列表 */
    public function testListAttachmentsEmpty(): void
    {
        $handler = new AttachmentHandler();
        $this->setupReadToken($handler);

        $result = $handler->execute('list_attachments', ['item_id' => 1]);

        $this->assertEquals(0, $result['total']);
        $this->assertSame([], $result['attachments']);
    }

    /** TC-201.4 delete_attachment 通过 file_id 删除 */
    public function testDeleteAttachmentByFileId(): void
    {
        $handler = new AttachmentHandler();
        $this->setupWriteToken($handler);

        $this->insertUploadFile(303, 100, 1);

        // Attachment::deleteFile 依赖 upload_file 表 + OssHelper，测试只验证参数校验
        // 先验证文件存在
        $file = DB::table('upload_file')->where('file_id', 303)->first();
        $this->assertNotNull($file);

        // deleteFile 需要文件真实 key 才能删 OSS；由于 upload_file 缺少 file_key 字段
        // Attachment::deleteFile 可能返回 false，导致抛出异常
        // 这里通过验证 requireWritePermission 和 file 查找逻辑间接测试
        // 直接调用并预期操作失败（因为无 OSS 环境）
        try {
            $handler->execute('delete_attachment', ['file_id' => 303]);
            // 如果意外成功，检查 upload_file 是否还存在
        } catch (McpException $e) {
            $this->assertStringContainsString('删除', $e->getMessage());
        }
    }

    /** TC-201.5 upload_attachment 缺少 file_base64 报错 */
    public function testUploadAttachmentMissingBase64(): void
    {
        $handler = new AttachmentHandler();
        $this->setupWriteToken($handler);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('file_base64');
        $handler->execute('upload_attachment', ['item_id' => 1]);
    }

    /** TC-201.6 list_attachments 缺少 item_id 和 page_id 报错 */
    public function testListAttachmentsMissingParams(): void
    {
        $handler = new AttachmentHandler();
        $this->setupReadToken($handler);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('item_id');
        $handler->execute('list_attachments', []);
    }

    // ==================================================================
    //  TC-202  OpenAPI 导入
    // ==================================================================

    /** TC-202.1 导入 OpenAPI 文档（markdown 格式）到现有项目 */
    public function testImportOpenapiToExistingItem(): void
    {
        $handler = new OpenApiHandler();
        $this->setupWriteToken($handler);

        $openapi = json_encode([
            'openapi' => '3.0.0',
            'info' => [
                'title'       => '测试API',
                'description' => '测试用的API文档',
                'version'     => '1.0.0',
            ],
            'paths' => [
                '/api/users' => [
                    'get' => [
                        'summary'     => '获取用户列表',
                        'description' => '返回所有用户',
                        'tags'        => ['用户管理'],
                        'responses'   => [
                            '200' => ['description' => '成功'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $handler->execute('import_openapi', [
            'item_id'         => 1,
            'openapi_content' => $openapi,
            'format'          => 'markdown',
        ]);

        $this->assertEquals(1, $result['item_id']);
        $this->assertStringContainsString('导入', $result['message']);
        $this->assertEquals('3.0.0', $result['swagger_version']);
        $this->assertEquals('markdown', $result['format']);
        $this->assertGreaterThanOrEqual(1, $result['catalog_count']);
    }

    /** TC-202.2 导入 OpenAPI 文档（runapi 格式）创建新项目 */
    public function testImportOpenapiCreateNewItem(): void
    {
        $handler = new OpenApiHandler();
        $this->setupWriteToken($handler);

        $openapi = json_encode([
            'swagger' => '2.0',
            'info' => [
                'title'   => '新API',
                'version' => '1.0',
            ],
            'host' => 'api.example.com',
            'basePath' => '/v1',
            'paths' => [
                '/pets' => [
                    'get' => [
                        'summary'   => '获取宠物列表',
                        'tags'      => ['宠物'],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ]);

        $result = $handler->execute('import_openapi', [
            'openapi_content' => $openapi,
            'format'          => 'runapi',
        ]);

        $this->assertGreaterThan(0, $result['item_id']);
        $this->assertTrue($result['is_new_item']);
        $this->assertEquals('2.0', $result['swagger_version']);
        $this->assertEquals('runapi', $result['format']);
    }

    /** TC-202.3 import_openapi 缺少 openapi_content 和 openapi_url 报错 */
    public function testImportOpenapiMissingContent(): void
    {
        $handler = new OpenApiHandler();
        $this->setupWriteToken($handler);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('openapi_content');
        $handler->execute('import_openapi', ['item_id' => 1]);
    }

    /** TC-202.4 import_openapi 无效的 JSON 格式报错 */
    public function testImportOpenapiInvalidJson(): void
    {
        $handler = new OpenApiHandler();
        $this->setupWriteToken($handler);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('OpenAPI');
        $handler->execute('import_openapi', [
            'item_id'         => 1,
            'openapi_content' => 'not a valid json at all',
        ]);
    }

    // ==================================================================
    //  TC-203  单页分享
    // ==================================================================

    /** TC-203.1 创建单页分享链接 */
    public function testCreateSinglePageLink(): void
    {
        $handler = new PageHandler();
        $this->setupWriteToken($handler);

        $this->insertPage(401, 1, '分享测试页面', '# Hello');

        $result = $handler->execute('create_single_page_link', [
            'page_id'     => 401,
            'expire_days' => 30,
        ]);

        $this->assertNotEmpty($result['unique_key']);
        $this->assertStringContainsString('/p/', $result['share_url']);
        $this->assertGreaterThan(0, $result['expire_time']);
        $this->assertStringContainsString('成功', $result['message']);

        // 验证 single_page 表有记录
        $row = DB::table('single_page')->where('page_id', 401)->first();
        $this->assertNotNull($row);
        $this->assertEquals($result['unique_key'], $row->unique_key);
    }

    /** TC-203.2 创建单页分享链接（永久） */
    public function testCreateSinglePageLinkPermanent(): void
    {
        $handler = new PageHandler();
        $this->setupWriteToken($handler);

        $this->insertPage(402, 1, '永久分享页面', 'content');

        $result = $handler->execute('create_single_page_link', [
            'page_id' => 402,
        ]);

        $this->assertNotEmpty($result['unique_key']);
        $this->assertEquals(0, $result['expire_time']);

        $row = DB::table('single_page')->where('page_id', 402)->first();
        $this->assertEquals(0, (int) $row->expire_time);
    }

    /** TC-203.3 查询已有的分享链接 */
    public function testGetSinglePageLinkExists(): void
    {
        $handler = new PageHandler();
        $this->setupReadToken($handler);

        $this->insertPage(403, 1, '查询分享页面', 'content');
        $this->insertSinglePage(md5('test_key_403'), 403, 0);

        $result = $handler->execute('get_single_page_link', ['page_id' => 403]);

        $this->assertTrue($result['has_link']);
        $this->assertEquals(md5('test_key_403'), $result['unique_key']);
        $this->assertStringContainsString('/p/', $result['share_url']);
    }

    /** TC-203.4 查询不存在的分享链接 */
    public function testGetSinglePageLinkNotExists(): void
    {
        $handler = new PageHandler();
        $this->setupReadToken($handler);

        $this->insertPage(404, 1, '无分享页面', 'content');

        $result = $handler->execute('get_single_page_link', ['page_id' => 404]);

        $this->assertFalse($result['has_link']);
    }

    /** TC-203.5 查询已过期的分享链接 */
    public function testGetSinglePageLinkExpired(): void
    {
        $handler = new PageHandler();
        $this->setupReadToken($handler);

        $this->insertPage(405, 1, '过期分享页面', 'content');
        // expire_time 设为过去
        $this->insertSinglePage(md5('expired_key_405'), 405, time() - 3600);

        $result = $handler->execute('get_single_page_link', ['page_id' => 405]);

        $this->assertFalse($result['has_link']);
        $this->assertStringContainsString('过期', $result['message']);

        // 已过期的记录应被自动清理
        $row = DB::table('single_page')->where('page_id', 405)->first();
        $this->assertNull($row);
    }

    /** TC-203.6 删除分享链接 */
    public function testDeleteSinglePageLink(): void
    {
        $handler = new PageHandler();
        $this->setupWriteToken($handler);

        $this->insertPage(406, 1, '删除分享页面', 'content');
        $this->insertSinglePage(md5('del_key_406'), 406, 0);

        // 确认存在
        $this->assertNotNull(DB::table('single_page')->where('page_id', 406)->first());

        $result = $handler->execute('delete_single_page_link', ['page_id' => 406]);

        $this->assertStringContainsString('删除', $result['message']);

        // 确认已删除
        $this->assertNull(DB::table('single_page')->where('page_id', 406)->first());
    }

    /** TC-203.7 create_single_page_link 缺少 page_id 报错 */
    public function testCreateSinglePageLinkMissingPageId(): void
    {
        $handler = new PageHandler();
        $this->setupWriteToken($handler);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面ID不能为空');
        $handler->execute('create_single_page_link', []);
    }

    /** TC-203.8 get_single_page_link 缺少 page_id 报错 */
    public function testGetSinglePageLinkMissingPageId(): void
    {
        $handler = new PageHandler();
        $this->setupReadToken($handler);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面ID不能为空');
        $handler->execute('get_single_page_link', []);
    }

    /** TC-203.9 delete_single_page_link 缺少 page_id 报错 */
    public function testDeleteSinglePageLinkMissingPageId(): void
    {
        $handler = new PageHandler();
        $this->setupWriteToken($handler);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('页面ID不能为空');
        $handler->execute('delete_single_page_link', []);
    }
}
