<?php

namespace Tests\Agent;

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use App\Mcp\Handler\PageHandler;
use App\Mcp\McpException;
use App\Mcp\McpError;
use App\Model\Page;

/**
 * AI Agent 搜索功能测试(开源版)
 *
 * 覆盖用例:
 * - TC-070: search_pages 项目内搜索(title/content/all 模式,去重,最多 50)
 * - TC-071: search_pages 全局搜索(不传 item_id,getUserItemIds)
 * - TC-072: search_all_pages(limit/mode 参数)
 * - TC-074: System Prompt 搜索引导验证
 *
 * 使用 SQLite 内存数据库,PageHandler 通过反射调用 private 方法。
 *
 * 与主版的差异(已裁剪/调整):
 *   - 移除 TC-073 extractSnippet 全部用例:开源版 PageHandler 已删除该方法。
 *   - page 改为单表存储:所有 page_NN 分表操作改为 DB::table('page')。
 *   - page_content 不压缩:测试数据直接存原始字符串(不再 ContentCodec::compress)。
 *   - searchPages 支持空格 OR 拆词（已对齐主版）。
 *     testSearchPagesMultipleKeywordsOr 验证空格拆分为多关键字、任一匹配即返回。
 *   - 搜索结果无 snippet 字段:testSearchPagesResultStructure 已移除该断言。
 *   - searchAllPages 结果无 keywords 键:testSearchAllPagesStructureMatchesSearchPages
 *     已移除该断言(结构同 searchPages,含 query/item_id/search_mode/pages/total)。
 *   - 无积分/敏感词相关断言。
 */
class SearchTest extends TestCase
{
    /** @var PageHandler */
    private $handler;

    protected function setUp(): void
    {
        $this->createTables();
        $this->seedData();

        // 创建 PageHandler 实例并设置 token 信息
        $this->handler = new PageHandler();
        $this->handler->setTokenInfo([
            'uid' => 1,
            'permission' => 'read',
            'scope' => 'all',
            'auth_type' => 'user',
        ]);
    }

    protected function tearDown(): void
    {
        // 清空数据避免测试间干扰
        $this->cleanData();
    }

    // ==================================================================
    //  数据库建表
    // ==================================================================

    private function createTables(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        // 开源版 bootstrap 已预创建全部表(含单表 page)。
        // 这里仅做防御性补建,确保即使 bootstrap 顺序变化也能运行。

        if (!$schema->hasTable('item')) {
            $schema->create('item', function ($table) {
                $table->increments('item_id');
                $table->string('item_name', 255)->default('');
                $table->integer('uid')->default(0);
                $table->integer('item_type')->default(1);
                $table->integer('is_del')->default(0);
                $table->string('password', 255)->nullable();
                $table->string('item_domain', 255)->nullable();
            });
        }

        if (!$schema->hasTable('user')) {
            $schema->create('user', function ($table) {
                $table->increments('uid');
                $table->string('username', 255)->default('');
                $table->integer('groupid')->default(0);
            });
        }

        if (!$schema->hasTable('item_member')) {
            $schema->create('item_member', function ($table) {
                $table->increments('id');
                $table->integer('item_id')->default(0);
                $table->integer('uid')->default(0);
                $table->integer('member_group_id')->default(0);
            });
        }

        if (!$schema->hasTable('team_item_member')) {
            $schema->create('team_item_member', function ($table) {
                $table->increments('id');
                $table->integer('item_id')->default(0);
                $table->integer('member_uid')->default(0);
                $table->integer('member_group_id')->default(0);
            });
        }

        if (!$schema->hasTable('options')) {
            $schema->create('options', function ($table) {
                $table->increments('id');
                $table->string('option_name', 255)->default('');
                $table->text('option_value')->nullable();
            });
        }

        if (!$schema->hasTable('item_ai_config')) {
            $schema->create('item_ai_config', function ($table) {
                $table->increments('id');
                $table->integer('item_id')->default(0);
                $table->tinyInteger('enabled')->default(0);
                $table->tinyInteger('dialog_collapsed')->default(1);
                $table->text('welcome_message')->nullable();
                $table->text('system_prompt')->nullable();
                $table->integer('addtime')->default(0);
                $table->integer('updatetime')->default(0);
            });
        }

        // 开源版:单表 page(bootstrap 已创建完整 schema)。这里仅在缺失时补建。
        if (!$schema->hasTable('page')) {
            $schema->create('page', function ($table) {
                $table->increments('page_id');
                $table->integer('item_id')->default(0);
                $table->integer('cat_id')->default(0);
                $table->string('page_title', 255)->default('');
                $table->text('page_content')->nullable();
                $table->integer('is_del')->default(0);
                $table->integer('is_draft')->default(0);
                $table->integer('s_number')->default(99);
                $table->integer('addtime')->default(0);
                $table->integer('author_uid')->default(0);
                $table->string('author_username', 255)->default('');
            });
        }
    }

    /**
     * 插入测试页面数据到单表 page(开源版不压缩、不分表)
     */
    private function insertPage(int $itemId, string $title, string $content = '', int $isDel = 0, int $catId = 0): int
    {
        // 开源版:page_content 直接存原文
        return DB::table('page')->insertGetId([
            'item_id' => $itemId,
            'cat_id' => $catId,
            'page_title' => $title,
            'page_content' => $content,
            'is_del' => $isDel,
            's_number' => 0,
            'author_uid' => 1,
            'author_username' => 'tester',
            'addtime' => time(),
        ]);
    }

    private function seedData(): void
    {
        // 用户
        DB::table('user')->insert([
            'uid' => 1, 'username' => 'testuser', 'groupid' => 0,
        ]);

        // 项目 1(用户创建)
        DB::table('item')->insert([
            'item_id' => 1, 'item_name' => 'Project Alpha', 'uid' => 1, 'item_type' => 1, 'is_del' => 0,
        ]);

        // 项目 2(用户创建)
        DB::table('item')->insert([
            'item_id' => 2, 'item_name' => 'Project Beta', 'uid' => 1, 'item_type' => 1, 'is_del' => 0,
        ]);

        // 项目 3(用户是 item_member,非创建者)
        DB::table('item')->insert([
            'item_id' => 3, 'item_name' => 'Project Gamma', 'uid' => 99, 'item_type' => 1, 'is_del' => 0,
        ]);
        DB::table('item_member')->insert([
            'item_id' => 3, 'uid' => 1, 'member_group_id' => 1,
        ]);

        // 项目 4(用户是 team_item_member)
        DB::table('item')->insert([
            'item_id' => 4, 'item_name' => 'Project Delta', 'uid' => 100, 'item_type' => 1, 'is_del' => 0,
        ]);
        DB::table('team_item_member')->insert([
            'item_id' => 4, 'member_uid' => 1, 'member_group_id' => 1,
        ]);

        // ---- 项目 1 的页面 ----
        $this->insertPage(1, 'API 登录接口', '用户登录API文档,支持账号密码登录');
        $this->insertPage(1, 'API 注册接口', '新用户注册流程,需要邮箱验证');
        $this->insertPage(1, '数据库设计文档', 'MySQL 表结构设计,包含用户表和订单表');
        $this->insertPage(1, 'CORS 跨域配置', '跨域资源共享配置说明,CORS 中间件设置');
        $this->insertPage(1, '日常笔记', '一些零散的笔记内容');

        // ---- 项目 2 的页面 ----
        $this->insertPage(2, 'API 登录文档', 'OAuth2 登录流程说明');
        $this->insertPage(2, '部署指南', 'Docker 部署相关文档');

        // ---- 项目 3 的页面 ----
        $this->insertPage(3, 'CORS 配置指南', 'Nginx CORS 反向代理配置');
        $this->insertPage(3, '测试页面', '普通内容');

        // ---- 项目 4 的页面 ----
        $this->insertPage(4, '团队协作指南', '团队 Wiki 使用说明');

        // 已删除的页面
        $this->insertPage(1, '已删除的 API 文档', '应不出现在搜索结果', 1);
    }

    private function cleanData(): void
    {
        DB::table('item')->delete();
        DB::table('user')->delete();
        DB::table('item_member')->delete();
        DB::table('team_item_member')->delete();
        DB::table('options')->delete();
        DB::table('item_ai_config')->delete();
        DB::table('page')->delete();
    }

    // ==================================================================
    //  辅助:通过反射调用 private 方法
    // ==================================================================

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($this->handler, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->handler, $args);
    }

    /**
     * 通过反射调用 searchPages(execute 路由到 searchPages)
     */
    private function searchPages(array $params): array
    {
        return $this->invokePrivate('searchPages', [$params]);
    }

    /**
     * 通过反射调用 searchAllPages
     */
    private function searchAllPages(array $params): array
    {
        return $this->invokePrivate('searchAllPages', [$params]);
    }

    // ==================================================================
    //  TC-070: search_pages 项目内搜索
    // ==================================================================

    /**
     * TC-070: 项目内搜索 title 模式返回匹配结果
     */
    public function testSearchPagesItemScopeTitleMode(): void
    {
        $result = $this->searchPages([
            'query' => 'API',
            'item_id' => 1,
            'search_mode' => 'title',
        ]);

        $this->assertEquals('API', $result['query']);
        $this->assertEquals(1, $result['item_id']);
        $this->assertEquals('title', $result['search_mode']);
        $this->assertGreaterThanOrEqual(2, $result['total'], '应至少匹配到 API 登录接口和 API 注册接口');
        $this->assertLessThanOrEqual(50, $result['total']);

        $titles = array_column($result['pages'], 'page_title');
        $this->assertContains('API 登录接口', $titles);
        $this->assertContains('API 注册接口', $titles);
    }

    /**
     * TC-070: 含特殊字符的标题可被整句匹配
     *
     * 注意:开源版 searchPages 不转义 LIKE 通配符(与主版不同),
     * 这里仅验证"标题中含 % 时仍可被搜到"。
     */
    public function testSearchPagesTitleWithSpecialChars(): void
    {
        // 插入一个含 % 的标题
        $this->insertPage(1, '50% 完成', '进度文档');

        $result = $this->searchPages([
            'query' => '50% 完成',
            'item_id' => 1,
            'search_mode' => 'title',
        ]);

        $titles = array_column($result['pages'], 'page_title');
        $this->assertContains('50% 完成', $titles);
    }

    /**
     * TC-070: content 模式 - PHP 层面在内容中匹配
     */
    public function testSearchPagesContentMode(): void
    {
        $result = $this->searchPages([
            'query' => '账号密码',
            'item_id' => 1,
            'search_mode' => 'content',
        ]);

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $titles = array_column($result['pages'], 'page_title');
        $this->assertContains('API 登录接口', $titles);
    }

    /**
     * TC-070: all 模式 - 标题+内容同时搜索
     */
    public function testSearchPagesAllMode(): void
    {
        // 搜索 "API" - 标题中有
        $result = $this->searchPages([
            'query' => 'API',
            'item_id' => 1,
            'search_mode' => 'all',
        ]);

        $titles = array_column($result['pages'], 'page_title');
        $this->assertContains('API 登录接口', $titles);
        $this->assertContains('API 注册接口', $titles);

        // 搜索 "邮箱验证" - 内容中有
        $result2 = $this->searchPages([
            'query' => '邮箱验证',
            'item_id' => 1,
            'search_mode' => 'all',
        ]);

        $titles2 = array_column($result2['pages'], 'page_title');
        $this->assertContains('API 注册接口', $titles2);
    }

    /**
     * TC-070: 空格分隔多关键字 OR 搜索（已对齐主版）
     */
    public function testSearchPagesMultipleKeywordsOr(): void
    {
        $result = $this->searchPages([
            'query' => 'API 数据库',
            'item_id' => 1,
            'search_mode' => 'title',
        ]);

        $titles = array_column($result['pages'], 'page_title');
        // "API" 匹配到 API 登录接口、API 注册接口
        $this->assertContains('API 登录接口', $titles);
        $this->assertContains('API 注册接口', $titles);
        // "数据库" 匹配到 数据库设计文档
        $this->assertContains('数据库设计文档', $titles);
    }

    /**
     * TC-070: 结果去重(item_id:page_id)
     */
    public function testSearchPagesDeduplication(): void
    {
        // "API 登录" 是 "API 登录接口" 的连续子串,应只命中一次
        $result = $this->searchPages([
            'query' => 'API 登录',
            'item_id' => 1,
            'search_mode' => 'title',
        ]);

        $pages = $result['pages'];
        $count = count(array_filter($pages, fn($p) => $p['page_title'] === 'API 登录接口'));
        $this->assertEquals(1, $count, '同一页面不应重复出现');

        // 更严格:检查所有 item_id:page_id 唯一
        $keys = array_map(fn($p) => $p['item_id'] . ':' . $p['page_id'], $pages);
        $this->assertEquals(count($keys), count(array_unique($keys)), '结果中不应有重复的 item_id:page_id');
    }

    /**
     * TC-070: 最多返回 50 条
     */
    public function testSearchPagesMaxFiftyResults(): void
    {
        // 插入大量含 "批量" 的页面
        for ($i = 0; $i < 60; $i++) {
            $this->insertPage(1, '批量测试页面_' . $i, '批量测试内容');
        }

        $result = $this->searchPages([
            'query' => '批量测试页面',
            'item_id' => 1,
            'search_mode' => 'title',
        ]);

        $this->assertLessThanOrEqual(50, $result['total'], '最多返回 50 条');
    }

    /**
     * TC-070: 已删除页面不出现在搜索结果
     */
    public function testSearchPagesExcludesDeletedPages(): void
    {
        $result = $this->searchPages([
            'query' => '已删除的 API 文档',
            'item_id' => 1,
            'search_mode' => 'title',
        ]);

        $titles = array_column($result['pages'], 'page_title');
        $this->assertNotContains('已删除的 API 文档', $titles);
    }

    /**
     * TC-070: 空查询抛异常
     */
    public function testSearchPagesEmptyQueryThrowsException(): void
    {
        $this->expectException(McpException::class);
        $this->searchPages([
            'query' => '',
            'item_id' => 1,
        ]);
    }

    /**
     * TC-070: 仅空格的查询抛异常
     */
    public function testSearchPagesWhitespaceOnlyQueryThrowsException(): void
    {
        $this->expectException(McpException::class);
        $this->searchPages([
            'query' => '   ',
            'item_id' => 1,
        ]);
    }

    /**
     * TC-070: 无效 search_mode 回退到 title
     */
    public function testSearchPagesInvalidModeFallsBackToTitle(): void
    {
        $result = $this->searchPages([
            'query' => 'API',
            'item_id' => 1,
            'search_mode' => 'invalid_mode',
        ]);

        $this->assertEquals('title', $result['search_mode']);
        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    /**
     * TC-070: 搜索结果包含正确的字段结构
     */
    public function testSearchPagesResultStructure(): void
    {
        $result = $this->searchPages([
            'query' => 'CORS',
            'item_id' => 1,
            'search_mode' => 'title',
        ]);

        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('item_id', $result);
        $this->assertArrayHasKey('search_mode', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('total', $result);

        if (!empty($result['pages'])) {
            $page = $result['pages'][0];
            $this->assertArrayHasKey('page_id', $page);
            $this->assertArrayHasKey('page_title', $page);
            $this->assertArrayHasKey('item_id', $page);
            $this->assertArrayHasKey('cat_id', $page);
            $this->assertArrayHasKey('addtime', $page);
        }
    }

    // ==================================================================
    //  TC-071: search_pages 全局搜索
    // ==================================================================

    /**
     * TC-071: 全局搜索不传 item_id,返回所有有权限项目的结果
     */
    public function testSearchPagesGlobalReturnsAllAccessibleProjects(): void
    {
        $result = $this->searchPages([
            'query' => 'CORS',
            'search_mode' => 'title',
        ]);

        $this->assertEquals(0, $result['item_id'], '全局搜索 item_id 应为 0');

        $itemIds = array_unique(array_column($result['pages'], 'item_id'));
        // CORS 出现在项目 1(CORS 跨域配置)和项目 3(CORS 配置指南)
        $this->assertContains(1, $itemIds, '应包含用户创建的项目 1');
        $this->assertContains(3, $itemIds, '应包含用户作为成员的项目 3');
    }

    /**
     * TC-071: getUserItemIds 包含用户创建的项目 + item_member + team_item_member
     */
    public function testGetUserItemIdsIncludesAllSources(): void
    {
        $result = $this->invokePrivate('getUserItemIds', [1]);

        $this->assertContains(1, $result, '应包含用户创建的项目');
        $this->assertContains(2, $result, '应包含用户创建的项目');
        $this->assertContains(3, $result, '应包含 item_member 的项目');
        $this->assertContains(4, $result, '应包含 team_item_member 的项目');
    }

    /**
     * TC-071: 全局搜索 title 模式跨项目命中
     */
    public function testGlobalSearchTitleModeHitsMultipleItems(): void
    {
        $result = $this->searchPages([
            'query' => 'API',
            'search_mode' => 'title',
        ]);

        // 应搜到多个项目中的 API 相关页面
        $titles = array_column($result['pages'], 'page_title');
        $this->assertContains('API 登录接口', $titles);
        $this->assertContains('API 注册接口', $titles);
        $this->assertContains('API 登录文档', $titles);
    }

    /**
     * TC-071: 全局搜索 content 模式逐项目匹配
     */
    public function testGlobalSearchContentMode(): void
    {
        $result = $this->searchPages([
            'query' => 'OAuth2',
            'search_mode' => 'content',
        ]);

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $titles = array_column($result['pages'], 'page_title');
        $this->assertContains('API 登录文档', $titles);
    }

    /**
     * TC-071: 全局搜索结果去重
     */
    public function testGlobalSearchDeduplication(): void
    {
        $result = $this->searchPages([
            'query' => 'API 登录',
            'search_mode' => 'title',
        ]);

        $keys = array_map(fn($p) => $p['item_id'] . ':' . $p['page_id'], $result['pages']);
        $this->assertEquals(count($keys), count(array_unique($keys)));
    }

    /**
     * TC-071: 全局搜索最多 50 条
     */
    public function testGlobalSearchMaxFifty(): void
    {
        // 在多个项目中插入大量匹配页面
        for ($i = 0; $i < 30; $i++) {
            $this->insertPage(1, '全局批量_' . $i, '');
            $this->insertPage(2, '全局批量_' . $i, '');
        }

        $result = $this->searchPages([
            'query' => '全局批量',
            'search_mode' => 'title',
        ]);

        $this->assertLessThanOrEqual(50, $result['total']);
    }

    // ==================================================================
    //  TC-072: search_all_pages
    // ==================================================================

    /**
     * TC-072: search_all_pages 不传 item_id,返回全局搜索结果
     */
    public function testSearchAllPagesReturnsGlobalResults(): void
    {
        $result = $this->searchAllPages([
            'keywords' => 'API',
            'mode' => 'title',
        ]);

        $this->assertEquals(0, $result['item_id']);
        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    /**
     * TC-072: limit 参数控制返回数量(默认 20)
     */
    public function testSearchAllPagesDefaultLimit(): void
    {
        // 插入超过 20 条结果
        for ($i = 0; $i < 25; $i++) {
            $this->insertPage(1, 'limit_test_' . $i, '');
        }

        $result = $this->searchAllPages([
            'keywords' => 'limit_test',
            'mode' => 'title',
        ]);

        $this->assertLessThanOrEqual(20, $result['total'], '默认 limit=20');
    }

    /**
     * TC-072: limit 参数控制返回数量(自定义值)
     */
    public function testSearchAllPagesCustomLimit(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->insertPage(1, 'custom_limit_' . $i, '');
        }

        $result = $this->searchAllPages([
            'keywords' => 'custom_limit',
            'mode' => 'title',
            'limit' => 5,
        ]);

        $this->assertLessThanOrEqual(5, $result['total'], '自定义 limit=5');
        $this->assertLessThanOrEqual(5, count($result['pages']));
    }

    /**
     * TC-072: limit 最大 50
     */
    public function testSearchAllPagesMaxLimit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->insertPage(1, 'max_limit_' . $i, '');
        }

        $result = $this->searchAllPages([
            'keywords' => 'max_limit',
            'mode' => 'title',
            'limit' => 999,  // 超过最大值
        ]);

        $this->assertLessThanOrEqual(50, $result['total'], 'limit 最大为 50');
    }

    /**
     * TC-072: limit <= 0 时回退到默认值 20
     */
    public function testSearchAllPagesInvalidLimitFallsBack(): void
    {
        $result = $this->searchAllPages([
            'keywords' => 'API',
            'mode' => 'title',
            'limit' => 0,
        ]);

        // 不报错,正常返回结果
        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    /**
     * TC-072: mode 参数 = content
     */
    public function testSearchAllPagesContentMode(): void
    {
        $result = $this->searchAllPages([
            'keywords' => '账号密码',
            'mode' => 'content',
        ]);

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $titles = array_column($result['pages'], 'page_title');
        $this->assertContains('API 登录接口', $titles);
    }

    /**
     * TC-072: mode 参数 = all
     */
    public function testSearchAllPagesAllMode(): void
    {
        $result = $this->searchAllPages([
            'keywords' => 'OAuth2',
            'mode' => 'all',
        ]);

        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    /**
     * TC-072: mode 无效值回退到 title
     */
    public function testSearchAllPagesInvalidModeFallsBack(): void
    {
        $result = $this->searchAllPages([
            'keywords' => 'API',
            'mode' => 'foobar',
        ]);

        $this->assertEquals('title', $result['search_mode']);
    }

    /**
     * TC-072: 空关键字抛异常
     */
    public function testSearchAllPagesEmptyKeywordsThrows(): void
    {
        $this->expectException(McpException::class);
        $this->searchAllPages([
            'keywords' => '',
        ]);
    }

    /**
     * TC-072: search_all_pages 复用 searchPages 逻辑(结果结构一致)
     *
     * 注意:开源版 searchAllPages 返回结构同 searchPages(含 query/item_id/
     * search_mode/pages/total),不含单独的 keywords 键。
     */
    public function testSearchAllPagesStructureMatchesSearchPages(): void
    {
        $result = $this->searchAllPages([
            'keywords' => 'CORS',
            'mode' => 'title',
        ]);

        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('item_id', $result);
        $this->assertArrayHasKey('search_mode', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('total', $result);
    }

    // ==================================================================
    //  TC-074: System Prompt 搜索引导验证
    // ==================================================================

    /**
     * 辅助:调用 AgentHelper::buildSystemPrompt
     */
    private function buildSystemPrompt(array $params): string
    {
        $host = new class {
            use \App\Common\Helper\AgentHelper;

            public string $aiSystemPrompt = '';
            public string $aiServiceUrl = '';
            public string $aiServiceToken = '';
            public string $aiModelName = '';
            public string $aiWelcomeMessage = '';
        };

        $ref = new \ReflectionMethod($host, 'buildSystemPrompt');
        $ref->setAccessible(true);
        return $ref->invoke($host, $params);
    }

    /**
     * TC-074: 全局会话 prompt 包含"不传 item_id 即可全局搜索"指引
     */
    public function testGlobalPromptContainsGlobalSearchGuidance(): void
    {
        $prompt = $this->buildSystemPrompt([
            'item_id' => 0,
            'item_name' => '',
            'page_id' => 0,
            'page_title' => '',
            'editor_content' => '',
            'role' => 'writer',
            'item_type' => 'regular',
        ]);

        $this->assertStringContainsString('不传 item_id', $prompt, '全局会话 prompt 应包含全局搜索指引');
        $this->assertStringContainsString('搜索用户有权限的所有项目', $prompt);
    }

    /**
     * TC-074: 项目会话 prompt 包含"必须传 item_id={itemId}"限制
     */
    public function testItemPromptContainsItemIdRestriction(): void
    {
        $itemId = 42;
        $prompt = $this->buildSystemPrompt([
            'item_id' => $itemId,
            'item_name' => '测试项目',
            'page_id' => 0,
            'page_title' => '',
            'editor_content' => '',
            'role' => 'writer',
            'item_type' => 'regular',
        ]);

        $this->assertStringContainsString("item_id={$itemId}", $prompt, '项目会话 prompt 应包含 item_id 限制');
        $this->assertStringContainsString('必须传入', $prompt);
    }

    /**
     * TC-074: prompt 包含"至少尝试 2-3 组关键字"策略指引
     */
    public function testPromptContainsMultipleKeywordsStrategy(): void
    {
        $prompt = $this->buildSystemPrompt([
            'item_id' => 0,
            'item_name' => '',
            'page_id' => 0,
            'page_title' => '',
            'editor_content' => '',
            'role' => 'writer',
            'item_type' => 'regular',
        ]);

        $this->assertStringContainsString('2-3 组关键字', $prompt, '应包含多关键字搜索策略');
    }

    /**
     * TC-074: prompt 包含中英文双语搜索建议
     */
    public function testPromptContainsBilingualSearchSuggestion(): void
    {
        $prompt = $this->buildSystemPrompt([
            'item_id' => 0,
            'item_name' => '',
            'page_id' => 0,
            'page_title' => '',
            'editor_content' => '',
            'role' => 'writer',
            'item_type' => 'regular',
        ]);

        $this->assertStringContainsString('中英文', $prompt, '应包含中英文搜索建议');
        $this->assertStringContainsString('CORS', $prompt, '应包含英文术语示例');
    }

    /**
     * TC-074: prompt 包含搜索结果只有摘要时的引导
     */
    public function testPromptContainsReadFullPageGuidance(): void
    {
        $prompt = $this->buildSystemPrompt([
            'item_id' => 0,
            'item_name' => '',
            'page_id' => 0,
            'page_title' => '',
            'editor_content' => '',
            'role' => 'writer',
            'item_type' => 'regular',
        ]);

        $this->assertStringContainsString('摘要', $prompt);
        $this->assertStringContainsString('完整页面内容', $prompt);
    }

    /**
     * TC-074: 项目会话 prompt 提示跨项目搜索需回到全局模式
     */
    public function testItemPromptMentionsGlobalModeForCrossProject(): void
    {
        $prompt = $this->buildSystemPrompt([
            'item_id' => 5,
            'item_name' => '当前项目',
            'page_id' => 0,
            'page_title' => '',
            'editor_content' => '',
            'role' => 'writer',
            'item_type' => 'regular',
        ]);

        $this->assertStringContainsString('跨项目', $prompt);
        $this->assertStringContainsString('全局助手模式', $prompt);
    }
}
