<?php

namespace Tests\Agent;

use PHPUnit\Framework\TestCase;
use App\Mcp\Handler\KanbanHandler;
use App\Mcp\McpException;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * KanbanHandler 单元测试（开源版）
 *
 * 直接实例化 KanbanHandler，通过 execute() 调用操作，
 * 使用 SQLite 内存库验证所有看板操作。
 *
 * 覆盖范围：
 *   kanban_get_board / kanban_get_lists / kanban_get_task
 *   kanban_list_tasks / kanban_search_tasks
 *   kanban_create_task / kanban_update_task / kanban_move_task / kanban_delete_task
 *   kanban_add_list / kanban_update_list / kanban_delete_list
 *   kanban_archive_list / kanban_restore_list / kanban_get_activity
 *
 * 与主版的差异：
 *   - 开源版看板使用单表 page 存储（page_content 不压缩），
 *     主版使用 page_NN 分表 + ContentCodec::compress。
 *     本测试 insertBoardPage/insertTaskPage 改为写入单表 page，
 *     编码改为 json + htmlspecialchars（不再 compress）。
 *   - 看板本身无积分逻辑，无需裁剪积分断言。
 */
class KanbanHandlerTest extends TestCase
{
    /** @var KanbanHandler */
    private KanbanHandler $handler;

    /** @var int 测试用户 UID */
    private int $uid = 100;

    /** @var int 测试项目 ID */
    private int $itemId = 1;

    /** @var string 测试列表 ID */
    private string $listId = 'list_todo';

    /** @var string 测试列表 ID 2 */
    private string $listId2 = 'list_done';

    /** @var int 看板页面 page_id */
    private int $boardPageId = 900;

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

    private function insertItem(int $itemId, string $name, int $uid = 100, int $itemType = 4): void
    {
        DB::table('item')->insert([
            'item_id' => $itemId, 'item_name' => $name,
            'uid' => $uid, 'item_type' => $itemType, 'is_del' => 0,
        ]);
    }

    /**
     * 插入看板板页面（__kanban_board__）
     *
     * 开源版使用单表 page，page_content 不压缩（json + htmlspecialchars）。
     */
    private function insertBoardPage(
        int $itemId,
        int $pageId,
        array $boardData
    ): void {
        $now = time();

        DB::table('page')->insert([
            'page_id'         => $pageId,
            'item_id'         => $itemId,
            'page_title'      => '__kanban_board__',
            'page_content'    => $this->encodeBoardContent($boardData),
            'is_del'          => 0,
            's_number'        => 0,
            'addtime'         => $now,
            'author_uid'      => $this->uid,
            'author_username' => 'testuser',
        ]);
    }

    /**
     * 编码看板数据为 page_content 格式（json + htmlspecialchars，开源版不压缩）
     */
    private function encodeBoardContent(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 编码任务数据为 page_content 格式（json + htmlspecialchars，开源版不压缩）
     */
    private function encodeTaskContent(array $taskData): string
    {
        $json = json_encode($taskData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 构建默认看板数据
     */
    private function defaultBoardData(): array
    {
        return [
            'lists' => [
                ['id' => $this->listId,  'title' => '待办', 'position' => 1],
                ['id' => $this->listId2, 'title' => '已完成', 'position' => 2],
            ],
            'tasks_order' => [
                $this->listId  => [],
                $this->listId2 => [],
            ],
        ];
    }

    /**
     * 插入任务页面（开源版单表 page）
     */
    private function insertTaskPage(
        int $pageId,
        int $itemId,
        string $title,
        array $taskData
    ): void {
        $now = time();

        DB::table('page')->insert([
            'page_id'         => $pageId,
            'item_id'         => $itemId,
            'page_title'      => $title,
            'page_content'    => $this->encodeTaskContent($taskData),
            'ext_info'        => json_encode([
                'completed' => (bool) ($taskData['completed'] ?? false),
                'tags'      => $taskData['tags'] ?? [],
                'priority'  => $taskData['priority'] ?? '',
                'assignee_uid'      => $taskData['assignee_uid'] ?? '',
                'assignee_username' => $taskData['assignee_username'] ?? '',
                'due_date'  => $taskData['due_date'] ?? '',
            ], JSON_UNESCAPED_UNICODE),
            'is_del'          => 0,
            's_number'        => 99,
            'addtime'         => $now,
            'author_uid'      => $taskData['creator_uid'] ?? $this->uid,
            'author_username' => $taskData['creator_username'] ?? 'testuser',
        ]);
    }

    // ------------------------------------------------------------------
    //  setUp / tearDown
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->handler = new KanbanHandler();
        $this->clearFileCache();
        $schema = DB::connection()->getSchemaBuilder();

        // 核心表（开源版单表架构，无分表）
        $tables = [
            'item' => function ($t) {
                $t->increments('item_id');
                $t->string('item_name', 255)->default('');
                $t->integer('uid')->default(0);
                $t->integer('item_type')->default(1);
                $t->integer('is_del')->default(0);
                $t->integer('last_update_time')->default(0);
            },
            'user' => function ($t) {
                $t->increments('uid');
                $t->string('username', 255)->default('');
                $t->integer('groupid')->default(0);
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
            'options' => function ($t) {
                $t->string('option_name', 255)->primary();
                $t->text('option_value')->nullable();
            },
            'kanban_activity_log' => function ($t) {
                $t->increments('id');
                $t->integer('item_id')->default(0);
                $t->integer('page_id')->default(0);
                $t->string('event_type', 64)->default('');
                $t->text('event_data')->nullable();
                $t->integer('operator_uid')->default(0);
                $t->integer('addtime')->default(0);
            },
        ];

        foreach ($tables as $name => $callback) {
            if ($schema->hasTable($name)) {
                $schema->drop($name);
            }
            $schema->create($name, $callback);
        }

        // 开源版单表 page（含看板需要的全部列）
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
        });

        // 默认测试数据
        $this->insertUser($this->uid, 'testuser');
        $this->insertItem($this->itemId, 'TestKanban', $this->uid, 4);
    }

    protected function tearDown(): void
    {
        $coreTables = ['item', 'page', 'user', 'item_member', 'team_item_member', 'options', 'kanban_activity_log'];
        foreach ($coreTables as $t) {
            try { DB::table($t)->delete(); } catch (\Throwable $e) {}
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

    /**
     * 准备一个带看板板的测试环境（只读 token）
     */
    private function prepareReadBoard(array $boardOverrides = []): array
    {
        $this->setupReadToken();
        $boardData = array_merge($this->defaultBoardData(), $boardOverrides);
        $this->insertBoardPage($this->itemId, $this->boardPageId, $boardData);
        return $boardData;
    }

    /**
     * 准备一个带看板板的测试环境（写入 token）
     */
    private function prepareWriteBoard(array $boardOverrides = []): array
    {
        $this->setupWriteToken();
        $boardData = array_merge($this->defaultBoardData(), $boardOverrides);
        $this->insertBoardPage($this->itemId, $this->boardPageId, $boardData);
        return $boardData;
    }

    // ==================================================================
    //  kanban_get_board
    // ==================================================================

    /** 获取完整看板数据 */
    public function testGetBoardSuccess(): void
    {
        $this->prepareReadBoard();

        $result = $this->handler->execute('kanban_get_board', ['item_id' => $this->itemId]);

        $this->assertEquals($this->itemId, $result['item_id']);
        $this->assertEquals($this->boardPageId, $result['page_id']);
        $this->assertCount(2, $result['lists']);
        $this->assertEquals('待办', $result['lists'][0]['title']);
        $this->assertEquals('已完成', $result['lists'][1]['title']);
    }

    // ==================================================================
    //  kanban_get_lists
    // ==================================================================

    /** 获取列表概要 */
    public function testGetListsSuccess(): void
    {
        $this->prepareReadBoard([
            'tasks_order' => [
                $this->listId  => ['301'],
                $this->listId2 => [],
            ],
        ]);

        $result = $this->handler->execute('kanban_get_lists', ['item_id' => $this->itemId]);

        $this->assertCount(2, $result['lists']);
        $this->assertEquals(1, $result['lists'][0]['task_count']);
        $this->assertEquals(0, $result['lists'][1]['task_count']);
    }

    // ==================================================================
    //  kanban_get_task
    // ==================================================================

    /** 通过 page_id 获取单个任务 */
    public function testGetTaskSuccess(): void
    {
        $this->prepareReadBoard();
        $taskId = 301;
        $taskData = [
            'list_id' => $this->listId,
            'description' => '任务描述',
            'assignee_uid' => '100',
            'assignee_username' => 'testuser',
            'creator_uid' => '100',
            'creator_username' => 'testuser',
            'due_date' => '2026-01-15',
            'tags' => [['text' => 'bug', 'color' => 'red']],
            'priority' => 'high',
            'completed' => false,
        ];
        $this->insertTaskPage($taskId, $this->itemId, '修复登录', $taskData);

        $result = $this->handler->execute('kanban_get_task', ['page_id' => $taskId]);

        $this->assertEquals($taskId, $result['page_id']);
        $this->assertEquals('修复登录', $result['page_title']);
        $this->assertEquals($this->listId, $result['list_id']);
        $this->assertEquals('待办', $result['list_name']);
        $this->assertEquals('high', $result['priority']);
        $this->assertFalse($result['completed']);
    }

    // ==================================================================
    //  kanban_list_tasks
    // ==================================================================

    /** 列出所有未完成任务 */
    public function testListTasksSuccess(): void
    {
        $this->prepareReadBoard([
            'tasks_order' => [
                $this->listId  => ['301', '302'],
                $this->listId2 => [],
            ],
        ]);
        $this->insertTaskPage(301, $this->itemId, '任务A', [
            'list_id' => $this->listId, 'completed' => false,
            'creator_uid' => '100', 'creator_username' => 'testuser',
        ]);
        $this->insertTaskPage(302, $this->itemId, '任务B', [
            'list_id' => $this->listId, 'completed' => false,
            'creator_uid' => '100', 'creator_username' => 'testuser',
        ]);

        $result = $this->handler->execute('kanban_list_tasks', ['item_id' => $this->itemId]);

        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['tasks']);
    }

    /** 按列表 ID 过滤 */
    public function testListTasksFilterByListId(): void
    {
        $this->prepareReadBoard([
            'tasks_order' => [
                $this->listId  => ['301'],
                $this->listId2 => ['302'],
            ],
        ]);
        $this->insertTaskPage(301, $this->itemId, '任务A', [
            'list_id' => $this->listId, 'completed' => false,
            'creator_uid' => '100', 'creator_username' => 'testuser',
        ]);
        $this->insertTaskPage(302, $this->itemId, '任务B', [
            'list_id' => $this->listId2, 'completed' => false,
            'creator_uid' => '100', 'creator_username' => 'testuser',
        ]);

        $result = $this->handler->execute('kanban_list_tasks', [
            'item_id' => $this->itemId, 'list_id' => $this->listId2,
        ]);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('任务B', $result['tasks'][0]['page_title']);
    }

    // ==================================================================
    //  kanban_search_tasks
    // ==================================================================

    /** 按标题关键字搜索任务 */
    public function testSearchTasksSuccess(): void
    {
        $this->prepareReadBoard([
            'tasks_order' => [
                $this->listId  => ['301', '302'],
                $this->listId2 => [],
            ],
        ]);
        $this->insertTaskPage(301, $this->itemId, '修复登录Bug', [
            'list_id' => $this->listId, 'priority' => 'high',
            'creator_uid' => '100', 'creator_username' => 'testuser',
        ]);
        $this->insertTaskPage(302, $this->itemId, '优化性能', [
            'list_id' => $this->listId, 'priority' => 'low',
            'creator_uid' => '100', 'creator_username' => 'testuser',
        ]);

        $result = $this->handler->execute('kanban_search_tasks', [
            'item_id' => $this->itemId, 'query' => '登录',
        ]);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('修复登录Bug', $result['tasks'][0]['page_title']);
    }

    // ==================================================================
    //  kanban_create_task
    // ==================================================================

    /** 创建任务成功 */
    public function testCreateTaskSuccess(): void
    {
        $this->prepareWriteBoard();

        $result = $this->handler->execute('kanban_create_task', [
            'item_id'  => $this->itemId,
            'list_id'  => $this->listId,
            'title'    => '新任务',
            'description' => '任务描述',
            'priority' => 'high',
            'tags' => [['text' => 'bug', 'color' => 'red']],
        ]);

        $this->assertGreaterThan(0, $result['page_id']);
        $this->assertEquals('新任务', $result['page_title']);
        $this->assertEquals($this->listId, $result['list_id']);
        $this->assertStringContainsString('成功', $result['message']);
    }

    /** 缺少列表 ID 报错 */
    public function testCreateTaskMissingListId(): void
    {
        $this->prepareWriteBoard();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('列表ID不能为空');
        $this->handler->execute('kanban_create_task', [
            'item_id' => $this->itemId, 'title' => '测试',
        ]);
    }

    /** 任务标题超过 100 字符报错 */
    public function testCreateTaskTitleTooLong(): void
    {
        $this->prepareWriteBoard();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('100');
        $this->handler->execute('kanban_create_task', [
            'item_id' => $this->itemId,
            'list_id' => $this->listId,
            'title'   => str_repeat('A', 101),
        ]);
    }

    // ==================================================================
    //  kanban_update_task
    // ==================================================================

    /** 更新任务描述和优先级 */
    public function testUpdateTaskSuccess(): void
    {
        $this->prepareWriteBoard();
        $taskId = 301;
        $this->insertTaskPage($taskId, $this->itemId, '原任务', [
            'list_id' => $this->listId, 'description' => '旧描述',
            'priority' => 'low', 'creator_uid' => '100', 'creator_username' => 'testuser',
        ]);

        $result = $this->handler->execute('kanban_update_task', [
            'page_id'     => $taskId,
            'description' => '新描述',
            'priority'    => 'high',
        ]);

        $this->assertEquals($taskId, $result['page_id']);
        $this->assertStringContainsString('成功', $result['message']);
    }

    /** 标记任务完成 */
    public function testUpdateTaskComplete(): void
    {
        $this->prepareWriteBoard();
        $taskId = 301;
        $this->insertTaskPage($taskId, $this->itemId, '待完成', [
            'list_id' => $this->listId, 'completed' => false,
            'creator_uid' => '100', 'creator_username' => 'testuser',
        ]);

        $this->handler->execute('kanban_update_task', [
            'page_id' => $taskId, 'completed' => true,
        ]);

        $task = $this->handler->execute('kanban_get_task', ['page_id' => $taskId]);
        $this->assertTrue($task['completed']);
    }

    // ==================================================================
    //  kanban_move_task
    // ==================================================================

    /** 移动任务到另一个列表 */
    public function testMoveTaskSuccess(): void
    {
        $this->prepareWriteBoard([
            'tasks_order' => [
                $this->listId  => ['301'],
                $this->listId2 => [],
            ],
        ]);
        $taskId = 301;
        $this->insertTaskPage($taskId, $this->itemId, '待移动', [
            'list_id' => $this->listId,
            'creator_uid' => '100', 'creator_username' => 'testuser',
        ]);

        $result = $this->handler->execute('kanban_move_task', [
            'page_id'        => $taskId,
            'target_list_id' => $this->listId2,
        ]);

        $this->assertEquals($taskId, $result['page_id']);
        $this->assertEquals($this->listId, $result['from_list_id']);
        $this->assertEquals($this->listId2, $result['to_list_id']);
        $this->assertStringContainsString('成功', $result['message']);
    }

    // ==================================================================
    //  kanban_delete_task
    // ==================================================================

    /** 删除任务（软删除） */
    public function testDeleteTaskSuccess(): void
    {
        $this->prepareWriteBoard([
            'tasks_order' => [
                $this->listId  => ['301'],
                $this->listId2 => [],
            ],
        ]);
        $taskId = 301;
        $this->insertTaskPage($taskId, $this->itemId, '待删除', [
            'list_id' => $this->listId,
            'creator_uid' => '100', 'creator_username' => 'testuser',
        ]);

        $result = $this->handler->execute('kanban_delete_task', ['page_id' => $taskId]);

        $this->assertEquals($taskId, $result['page_id']);
        $this->assertStringContainsString('删除', $result['message']);
    }

    /** editor 成员不可删除看板任务（requireManagePermission 仅 owner/admin，验证 S1 修复） */
    public function testDeleteTaskDeniedForEditor(): void
    {
        $this->prepareWriteBoard([
            'tasks_order' => [
                $this->listId  => ['301'],
                $this->listId2 => [],
            ],
        ]);
        $taskId = 301;
        $this->insertTaskPage($taskId, $this->itemId, '待删除', [
            'list_id' => $this->listId,
            'creator_uid' => '100', 'creator_username' => 'testuser',
        ]);

        // uid=101 作为 editor 成员（member_group_id=1）：可编辑但不可删除任务
        $this->insertUser(101, 'editor');
        DB::table('item_member')->insert([
            'item_id' => $this->itemId, 'uid' => 101, 'member_group_id' => 1,
        ]);
        $this->setupWriteToken(101);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('权限不足');
        $this->handler->execute('kanban_delete_task', ['page_id' => $taskId]);
    }

    /** 非项目成员不可查看看板（requireReadPermission → NOT_ITEM_MEMBER） */
    public function testGetBoardDeniedForNonMember(): void
    {
        $this->prepareWriteBoard();
        $this->insertUser(103, 'outsider');
        $this->setupReadToken(103);

        $this->expectException(McpException::class);
        $this->handler->execute('kanban_get_board', ['item_id' => $this->itemId]);
    }

    // ==================================================================
    //  kanban_add_list
    // ==================================================================

    /** 添加新列表 */
    public function testAddListSuccess(): void
    {
        $this->prepareWriteBoard();

        $result = $this->handler->execute('kanban_add_list', [
            'item_id' => $this->itemId,
            'title'   => '审核中',
        ]);

        $this->assertNotEmpty($result['list_id']);
        $this->assertEquals('审核中', $result['title']);
        $this->assertEquals(3, $result['position']); // 第三个列表
        $this->assertStringContainsString('成功', $result['message']);
    }

    // ==================================================================
    //  kanban_update_list
    // ==================================================================

    /** 更新列表标题 */
    public function testUpdateListSuccess(): void
    {
        $this->prepareWriteBoard();

        $result = $this->handler->execute('kanban_update_list', [
            'item_id' => $this->itemId,
            'list_id' => $this->listId,
            'title'   => '待处理',
        ]);

        $this->assertEquals($this->listId, $result['list_id']);
        $this->assertEquals('待处理', $result['title']);
        $this->assertStringContainsString('成功', $result['message']);
    }

    // ==================================================================
    //  kanban_delete_list
    // ==================================================================

    /** 删除列表（最后一个列表禁止删除） */
    public function testDeleteLastListForbidden(): void
    {
        $this->prepareWriteBoard([
            'lists' => [
                ['id' => $this->listId, 'title' => '唯一列表', 'position' => 1],
            ],
            'tasks_order' => [
                $this->listId => [],
            ],
        ]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('唯一列表');
        $this->handler->execute('kanban_delete_list', [
            'item_id' => $this->itemId,
            'list_id' => $this->listId,
        ]);
    }

    // ==================================================================
    //  kanban_archive_list
    // ==================================================================

    /** 归档列表 */
    public function testArchiveListSuccess(): void
    {
        $this->prepareWriteBoard();

        $result = $this->handler->execute('kanban_archive_list', [
            'item_id' => $this->itemId,
            'list_id' => $this->listId,
        ]);

        $this->assertEquals($this->listId, $result['list_id']);
        $this->assertEquals('待办', $result['list_title']);
        $this->assertStringContainsString('归档', $result['message']);
    }

    // ==================================================================
    //  kanban_restore_list
    // ==================================================================

    /** 恢复归档列表 */
    public function testRestoreListSuccess(): void
    {
        // 先归档
        $this->prepareWriteBoard();
        $this->handler->execute('kanban_archive_list', [
            'item_id' => $this->itemId,
            'list_id' => $this->listId,
        ]);

        // 再恢复
        $result = $this->handler->execute('kanban_restore_list', [
            'item_id' => $this->itemId,
            'list_id' => $this->listId,
        ]);

        $this->assertEquals($this->listId, $result['list_id']);
        $this->assertEquals('待办', $result['list_title']);
        $this->assertStringContainsString('恢复', $result['message']);
    }

    // ==================================================================
    //  kanban_get_activity
    // ==================================================================

    /** 获取活动日志 */
    public function testGetActivitySuccess(): void
    {
        $this->prepareWriteBoard();

        // 先创建一个任务来产生活动日志
        $this->handler->execute('kanban_create_task', [
            'item_id'  => $this->itemId,
            'list_id'  => $this->listId,
            'title'    => '活动测试',
        ]);

        // 用 read token 查询活动
        $this->setupReadToken();
        $result = $this->handler->execute('kanban_get_activity', ['item_id' => $this->itemId]);

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $this->assertCount(1, $result['activities']);
        $this->assertEquals('task_created', $result['activities'][0]['event_type']);
    }

    /** 按事件类型过滤活动 */
    public function testGetActivityFilterByType(): void
    {
        $this->prepareWriteBoard();
        $this->handler->execute('kanban_create_task', [
            'item_id' => $this->itemId, 'list_id' => $this->listId, 'title' => '任务1',
        ]);
        $this->handler->execute('kanban_add_list', [
            'item_id' => $this->itemId, 'title' => '新列表',
        ]);

        $this->setupReadToken();
        $result = $this->handler->execute('kanban_get_activity', [
            'item_id'     => $this->itemId,
            'event_types' => ['task_created'],
        ]);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('task_created', $result['activities'][0]['event_type']);
    }
}
