<?php

namespace Tests\Agent;

use PHPUnit\Framework\TestCase;
use App\Model\Item;
use App\Model\Page;
use App\Common\Helper\ContentCodec;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * 看板项目复制时页面ID映射替换测试（开源版）
 *
 * 验证 Item::copy() / Item::import() 在复制看板项目时：
 *   - tasks_order / archived_tasks_order 中的页面ID被正确重映射
 *   - 任务 linked_pages 中的项目内引用被重映射，跨项目引用保持不变
 *   - 非看板项目复制不受影响
 *
 * 注意：开源版使用单表 page 存储（不压缩 page_content），与主版分表架构不同。
 */
class KanbanCopyRemapTest extends TestCase
{
    /** @var int 测试用户 UID */
    private int $uid = 200;

    /** @var int 源看板项目 ID */
    private int $sourceItemId = 1;

    /** @var int 源板面页面 page_id */
    private int $sourceBoardPageId = 100;

    /** @var int 源任务1 page_id（在 list_a） */
    private int $sourceTask1Id = 101;

    /** @var int 源任务2 page_id（在 list_a，被任务1关联） */
    private int $sourceTask2Id = 102;

    /** @var int 源任务3 page_id（已归档） */
    private int $sourceTask3Id = 103;

    // ------------------------------------------------------------------
    //  编码/解码辅助（开源版：不压缩，仅 htmlspecialchars + json）
    // ------------------------------------------------------------------

    /**
     * 编码看板/任务数据为 page_content 格式（json + htmlspecialchars）
     */
    private function encodeContent(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 解码 page_content 为数组
     */
    private function decodePageContent(string $raw): ?array
    {
        $content = htmlspecialchars_decode($raw, ENT_QUOTES);
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    // ------------------------------------------------------------------
    //  数据插入辅助（开源版：单表 page）
    // ------------------------------------------------------------------

    /**
     * 插入页面到 page 表（开源版单表存储）
     */
    private function insertPage(int $itemId, int $pageId, string $title, string $content, int $sNumber = 99): void
    {
        DB::table('page')->insert([
            'page_id'         => $pageId,
            'item_id'         => $itemId,
            'cat_id'          => 0,
            'page_title'      => $title,
            'page_content'    => $content,
            'page_comments'   => '',
            'ext_info'        => null,
            'is_del'          => 0,
            'is_draft'        => 0,
            's_number'        => $sNumber,
            'addtime'         => time(),
            'author_uid'      => $this->uid,
            'author_username' => 'testuser',
        ]);
    }

    /**
     * 插入源看板项目（含板面 + 3个任务）
     */
    private function insertSourceKanban(): void
    {
        DB::table('item')->insert([
            'item_id'   => $this->sourceItemId,
            'item_name' => '看板示例',
            'item_type' => 6,
            'uid'       => $this->uid,
            'is_del'    => 0,
        ]);

        // 板面页面
        $boardData = [
            'lists' => [
                ['id' => 'list_a', 'title' => '待办', 'position' => 1],
                ['id' => 'list_b', 'title' => '进行中', 'position' => 2],
            ],
            'tasks_order' => [
                'list_a' => [(string) $this->sourceTask1Id, (string) $this->sourceTask2Id],
                'list_b' => [],
            ],
            'archived_lists' => [
                ['id' => 'list_arch', 'title' => '已完成(归档)', 'position' => 3],
            ],
            'archived_tasks_order' => [
                'list_arch' => [(string) $this->sourceTask3Id],
            ],
            'meta' => ['version' => 2, 'last_updated' => 0],
        ];
        $this->insertPage(
            $this->sourceItemId,
            $this->sourceBoardPageId,
            '__kanban_board__',
            $this->encodeContent($boardData),
            0
        );

        // 任务1：linked_pages 引用同项目任务2 + 跨项目外部页面(99999)
        $task1Data = [
            'list_id'          => 'list_a',
            'description'      => '任务1描述',
            'assignee_uid'     => '',
            'assignee_username' => '',
            'creator_uid'      => (string) $this->uid,
            'creator_username' => 'testuser',
            'due_date'         => '',
            'tags'             => [],
            'priority'         => 'medium',
            'linked_pages'     => [
                ['item_id' => (string) $this->sourceItemId, 'page_id' => (string) $this->sourceTask2Id, 'page_title' => '任务2'],
                ['item_id' => '88888', 'page_id' => '99999', 'page_title' => '外部页面'],
            ],
            'completed'        => false,
        ];
        $this->insertPage(
            $this->sourceItemId,
            $this->sourceTask1Id,
            '任务1',
            $this->encodeContent($task1Data)
        );

        // 任务2：无 linked_pages
        $task2Data = [
            'list_id'          => 'list_a',
            'description'      => '任务2描述',
            'creator_uid'      => (string) $this->uid,
            'creator_username' => 'testuser',
            'linked_pages'     => [],
            'completed'        => false,
            'priority'         => 'low',
        ];
        $this->insertPage(
            $this->sourceItemId,
            $this->sourceTask2Id,
            '任务2',
            $this->encodeContent($task2Data)
        );

        // 任务3（已归档）：linked_pages 引用同项目任务1
        $task3Data = [
            'list_id'          => 'list_arch',
            'description'      => '归档任务',
            'creator_uid'      => (string) $this->uid,
            'creator_username' => 'testuser',
            'linked_pages'     => [
                ['item_id' => (string) $this->sourceItemId, 'page_id' => (string) $this->sourceTask1Id, 'page_title' => '任务1'],
            ],
            'completed'        => true,
            'priority'         => 'medium',
        ];
        $this->insertPage(
            $this->sourceItemId,
            $this->sourceTask3Id,
            '任务3',
            $this->encodeContent($task3Data)
        );
    }

    /**
     * 读取新项目的板面数据
     */
    private function readBoardData(int $itemId): ?array
    {
        $board = DB::table('page')
            ->where('item_id', $itemId)
            ->where('page_title', '__kanban_board__')
            ->where('is_del', 0)
            ->first();
        if (!$board) {
            return null;
        }
        return $this->decodePageContent($board->page_content ?? '');
    }

    /**
     * 读取新项目的任务页面（page_id => [title, data]）
     */
    private function readTaskPages(int $itemId): array
    {
        $rows = DB::table('page')
            ->where('item_id', $itemId)
            ->where('is_del', 0)
            ->where('page_title', '<>', '__kanban_board__')
            ->get(['page_id', 'page_title', 'page_content'])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->page_id] = [
                'title' => $row->page_title,
                'data'  => $this->decodePageContent($row->page_content ?? ''),
            ];
        }
        return $result;
    }

    // ------------------------------------------------------------------
    //  setUp / tearDown
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        // 重建 item 表（含 import() 需要的全部字段）
        $this->recreateTable($schema, 'item', function ($t) {
            $t->increments('item_id');
            $t->string('item_name', 255)->default('');
            $t->string('item_description', 1000)->default('');
            $t->integer('item_type')->default(1);
            $t->integer('uid')->default(0);
            $t->string('username', 255)->default('');
            $t->string('password', 255)->nullable();
            $t->integer('addtime')->default(0);
            $t->integer('last_update_time')->default(0);
            $t->string('item_domain', 255)->nullable();
            $t->integer('is_del')->default(0);
        });

        // 重建 user 表（ItemMember::getList 关联 user.name）
        $this->recreateTable($schema, 'user', function ($t) {
            $t->increments('uid');
            $t->string('username', 255)->default('');
            $t->string('name', 255)->nullable();
            $t->string('password', 255)->default('');
            $t->integer('groupid')->default(0);
            $t->string('email', 255)->default('');
            $t->integer('email_verify')->default(0);
        });

        // 重建 item_member 表（getList 需要 addtime 字段）
        $this->recreateTable($schema, 'item_member', function ($t) {
            $t->increments('id');
            $t->integer('item_id')->default(0);
            $t->integer('uid')->default(0);
            $t->integer('member_group_id')->default(0);
            $t->integer('addtime')->default(0);
        });

        // 清理关联表
        if ($schema->hasTable('catalog')) {
            DB::table('catalog')->delete();
        }

        // 测试用户
        DB::table('user')->insert([
            'uid'      => $this->uid,
            'username' => 'testuser',
            'name'     => 'Test User',
        ]);
    }

    private function recreateTable($schema, string $name, callable $callback): void
    {
        if ($schema->hasTable($name)) {
            $schema->drop($name);
        }
        $schema->create($name, $callback);
    }

    protected function tearDown(): void
    {
        DB::table('item')->delete();
        DB::table('page')->delete();
        DB::table('user')->delete();
        DB::table('item_member')->delete();
        if (DB::connection()->getSchemaBuilder()->hasTable('catalog')) {
            DB::table('catalog')->delete();
        }
    }

    // ==================================================================
    //  测试用例
    // ==================================================================

    /**
     * 测试1：复制看板项目后，tasks_order 中的页面ID被正确重映射
     */
    public function testKanbanCopyRemapsTasksOrder(): void
    {
        $this->insertSourceKanban();

        $newItemId = Item::copy($this->sourceItemId, $this->uid, '看板副本');
        $this->assertGreaterThan(0, $newItemId, '复制应返回新项目ID');

        $boardData = $this->readBoardData($newItemId);
        $this->assertNotNull($boardData, '新项目应有可解析的板面数据');

        // 收集新项目的全部任务页面ID
        $newTasks = $this->readTaskPages($newItemId);
        $newTaskIds = array_keys($newTasks);
        $this->assertCount(3, $newTaskIds, '新项目应有3个任务页面');

        // tasks_order 的 list_a 应包含2个任务，且引用的是新页面ID
        $listATasks = $boardData['tasks_order']['list_a'] ?? [];
        $this->assertCount(2, $listATasks, 'list_a 应有2个任务');
        foreach ($listATasks as $pid) {
            $this->assertContains(
                (int) $pid,
                $newTaskIds,
                'tasks_order 应引用新项目的页面ID，实际: ' . $pid
            );
        }

        // 不应残留源项目的旧页面ID
        $sourceIds = [$this->sourceTask1Id, $this->sourceTask2Id, $this->sourceTask3Id];
        foreach ($listATasks as $pid) {
            $this->assertNotContains(
                (int) $pid,
                $sourceIds,
                'tasks_order 不应引用源项目的旧页面ID'
            );
        }
    }

    /**
     * 测试2：复制看板项目后，archived_tasks_order 也被正确重映射
     */
    public function testKanbanCopyRemapsArchivedTasksOrder(): void
    {
        $this->insertSourceKanban();

        $newItemId = Item::copy($this->sourceItemId, $this->uid, '看板副本');

        $boardData = $this->readBoardData($newItemId);
        $this->assertNotNull($boardData);

        $newTasks = $this->readTaskPages($newItemId);
        $newTaskIds = array_keys($newTasks);

        $archivedTasks = $boardData['archived_tasks_order']['list_arch'] ?? [];
        $this->assertCount(1, $archivedTasks, '归档列表应有1个任务');
        $this->assertContains(
            (int) $archivedTasks[0],
            $newTaskIds,
            '归档任务应引用新项目的页面ID'
        );
    }

    /**
     * 测试3：复制看板项目后，任务的 linked_pages 项目内引用被重映射，跨项目引用保持不变
     */
    public function testKanbanCopyRemapsLinkedPages(): void
    {
        $this->insertSourceKanban();

        $newItemId = Item::copy($this->sourceItemId, $this->uid, '看板副本');

        $newTasks = $this->readTaskPages($newItemId);
        $this->assertNotEmpty($newTasks);

        // 通过标题找到「任务1」（原 linked_pages 引用任务2 + 外部页面99999）
        $task1 = null;
        $task2NewId = null;
        foreach ($newTasks as $pid => $info) {
            if ($info['title'] === '任务1') {
                $task1 = $info['data'];
            }
            if ($info['title'] === '任务2') {
                $task2NewId = $pid;
            }
        }
        $this->assertNotNull($task1, '应找到任务1');
        $this->assertNotNull($task2NewId, '应找到任务2');

        $linkedPages = $task1['linked_pages'] ?? [];
        $this->assertCount(2, $linkedPages, '任务1应有2个关联页面');

        // 第一个关联页面（原任务2）应被重映射到新的任务2 page_id 和新 item_id
        $firstLinked = $linkedPages[0];
        $this->assertEquals((string) $task2NewId, $firstLinked['page_id'], '项目内 linked_pages 应重映射为新页面ID');
        $this->assertEquals((string) $newItemId, $firstLinked['item_id'], '项目内 linked_pages 的 item_id 应更新为新项目ID');

        // 第二个关联页面（外部页面99999）应保持不变
        $secondLinked = $linkedPages[1];
        $this->assertEquals('99999', $secondLinked['page_id'], '跨项目 linked_pages 不应被重映射');
        $this->assertEquals('88888', $secondLinked['item_id'], '跨项目 linked_pages 的 item_id 不应改变');
    }

    /**
     * 测试4：复制看板项目后，板面数据中的 tasks_order 引用的页面确实存在于新项目
     */
    public function testKanbanCopyReferencedPagesExist(): void
    {
        $this->insertSourceKanban();

        $newItemId = Item::copy($this->sourceItemId, $this->uid, '看板副本');

        $boardData = $this->readBoardData($newItemId);
        $this->assertNotNull($boardData);

        $allReferencedIds = [];
        foreach (($boardData['tasks_order'] ?? []) as $taskIds) {
            foreach ($taskIds as $pid) {
                $allReferencedIds[] = (int) $pid;
            }
        }
        foreach (($boardData['archived_tasks_order'] ?? []) as $taskIds) {
            foreach ($taskIds as $pid) {
                $allReferencedIds[] = (int) $pid;
            }
        }

        $this->assertNotEmpty($allReferencedIds, '板面应引用至少一个任务');

        foreach ($allReferencedIds as $pid) {
            $exists = DB::table('page')
                ->where('page_id', $pid)
                ->where('item_id', $newItemId)
                ->where('is_del', 0)
                ->exists();
            $this->assertTrue($exists, "tasks_order 引用的页面 {$pid} 应存在于新项目");
        }
    }

    /**
     * 测试5：非看板项目复制不受影响（普通 Markdown 项目）
     */
    public function testNonKanbanCopyUnaffected(): void
    {
        // 创建普通文档项目（item_type=1）
        $sourceId = 10;
        DB::table('item')->insert([
            'item_id'   => $sourceId,
            'item_name' => '普通文档',
            'item_type' => 1,
            'uid'       => $this->uid,
            'is_del'    => 0,
        ]);

        // 开源版 page_content 不压缩，直接存储 Markdown
        $this->insertPage($sourceId, 200, '首页', '# 标题' . "\n\n" . '这是一段Markdown内容');
        $this->insertPage($sourceId, 201, '第二页', '第二页内容');

        $newItemId = Item::copy($sourceId, $this->uid, '普通文档副本');
        $this->assertGreaterThan(0, $newItemId, '复制应返回新项目ID');
        $this->assertNotEquals($sourceId, $newItemId, '新项目ID应不同于源项目');

        // 新项目不应有 __kanban_board__ 页面
        $hasBoard = DB::table('page')
            ->where('item_id', $newItemId)
            ->where('page_title', '__kanban_board__')
            ->exists();
        $this->assertFalse($hasBoard, '普通项目不应有看板板面页面');

        // 新项目应有2个页面，内容正确
        $pages = DB::table('page')
            ->where('item_id', $newItemId)
            ->where('is_del', 0)
            ->get(['page_id', 'page_title', 'page_content'])
            ->all();
        $this->assertCount(2, $pages, '普通项目复制后应有2个页面');

        $titles = array_map(fn($p) => $p->page_title, $pages);
        $this->assertEqualsCanonicalizing(['首页', '第二页'], $titles, '页面标题应正确复制');
    }

    /**
     * 测试6：通过 import() 直接导入看板 JSON 也能触发重映射
     */
    public function testImportKanbanJsonRemapsPageIds(): void
    {
        // 构造看板项目 JSON（模拟 export 输出）
        // 使用固定旧 page_id：1001（板面）、1002（任务A）、1003（任务B）
        $json = json_encode([
            'item_type'        => 6,
            'item_name'        => '导入看板',
            'item_description' => '',
            'password'         => '',
            'pages' => [
                'pages' => [
                    ['page_id' => 1001, 'page_title' => '__kanban_board__', 'cat_id' => 0,
                     'page_content' => htmlspecialchars(json_encode([
                         'lists' => [['id' => 'list_x', 'title' => '待办', 'position' => 1]],
                         'tasks_order' => ['list_x' => ['1002', '1003']],
                         'archived_lists' => [],
                         'archived_tasks_order' => [],
                     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'),
                     's_number' => 0, 'page_comments' => '', 'is_draft' => 0],
                    ['page_id' => 1002, 'page_title' => '任务A', 'cat_id' => 0,
                     'page_content' => htmlspecialchars(json_encode([
                         'list_id' => 'list_x', 'description' => '导入任务A',
                         'linked_pages' => [], 'completed' => false, 'priority' => 'medium',
                     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'),
                     's_number' => 99, 'page_comments' => '', 'is_draft' => 0],
                    ['page_id' => 1003, 'page_title' => '任务B', 'cat_id' => 0,
                     'page_content' => htmlspecialchars(json_encode([
                         'list_id' => 'list_x', 'description' => '导入任务B',
                         'linked_pages' => [], 'completed' => false, 'priority' => 'low',
                     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'),
                     's_number' => 99, 'page_comments' => '', 'is_draft' => 0],
                ],
                'catalogs' => [],
            ],
            'members' => [],
        ], JSON_UNESCAPED_UNICODE);

        $newItemId = Item::import($json, $this->uid);
        $this->assertGreaterThan(0, $newItemId, '导入应返回新项目ID');

        $boardData = $this->readBoardData($newItemId);
        $this->assertNotNull($boardData, '导入的项目应有板面数据');

        $listXTasks = $boardData['tasks_order']['list_x'] ?? [];
        $this->assertCount(2, $listXTasks, '应有2个任务');

        // 引用的页面ID不应是旧的 1002/1003
        foreach ($listXTasks as $pid) {
            $this->assertNotContains((int) $pid, [1002, 1003], '不应引用旧页面ID');
        }

        // 新任务页面应存在
        $newTasks = $this->readTaskPages($newItemId);
        $this->assertCount(2, $newTasks, '应有2个任务页面');
        foreach ($listXTasks as $pid) {
            $this->assertArrayHasKey((int) $pid, $newTasks, '引用的页面应存在');
        }
    }
}
