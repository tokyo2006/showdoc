<?php

namespace Tests\Agent;

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * AI Agent 路由冒烟测试（开源版）
 *
 * 开源版路由为约定式通配：/{module}/{controller}/{action}[/{params:.*}]，
 * 无 routes.php。路径拍平：/api/agent/session/load → /api/agent/sessionLoad。
 * module=api → Api，controller=agent → AgentController，
 * action=strtolower(sessionLoad)=sessionload → PHP 方法调用大小写不敏感 → sessionLoad()。
 *
 * 本测试复制 index.php 的通配路由分发器，验证所有 Agent 端点的拍平路径可达（非 404）。
 *
 * 与主版的差异：
 *   - 主版用 routes.php 显式注册 11 条 /api/agent/* 路由（含 stats/user、stats/admin）。
 *   - 开源版改为通配分发；测试改用拍平路径验证。
 *   - 删除 statsUser / statsAdmin 路由测试（开源版 AgentController 无 stats 方法）。
 *   - 未注册路由不再抛 HttpNotFoundException（通配路由会命中所有 3 段路径），
 *     改为验证未知方法/控制器返回 404 状态码。
 */
class RouteSmokeTest extends TestCase
{
    /** @var \Slim\App */
    private $app;

    protected function setUp(): void
    {
        $this->app = $this->createApp();

        // 每次清空数据，保留表结构（开源版无 vip 表）
        $tables = [
            'options', 'user_token', 'ai_chat_sessions', 'ai_chat_messages',
            'item', 'item_ai_config', 'item_member', 'team_item_member',
        ];
        foreach ($tables as $t) {
            try { DB::table($t)->delete(); } catch (\Throwable $e) {}
        }

        // 插入 AI 服务配置（让 checkAiEnabled 通过）
        DB::table('options')->insert([
            ['option_name' => 'open_ai_host', 'option_value' => 'https://ai.example.com/v1'],
            ['option_name' => 'open_ai_key', 'option_value' => 'test-token-123'],
            ['option_name' => 'ai_model_name', 'option_value' => 'gpt-test'],
            ['option_name' => 'ai_welcome_message', 'option_value' => 'Hello'],
            ['option_name' => 'ai_allow_guest', 'option_value' => '1'],
        ]);
    }

    // ==================================================================
    //  路由冒烟测试：所有拍平端点
    // ==================================================================

    public function testSessionLoadRoute(): void
    {
        $this->assertRouteNot404('POST', '/api/agent/sessionLoad');
    }

    public function testSessionNewRoute(): void
    {
        $this->assertRouteNot404('POST', '/api/agent/sessionNew');
    }

    public function testSessionResetRoute(): void
    {
        $this->assertRouteNot404('POST', '/api/agent/sessionReset');
    }

    public function testSessionListRoute(): void
    {
        $this->assertRouteNot404('POST', '/api/agent/sessionList');
    }

    public function testSessionDeleteRoute(): void
    {
        $this->assertRouteNot404('POST', '/api/agent/sessionDelete');
    }

    public function testAgentRoute(): void
    {
        $this->assertRouteNot404('POST', '/api/agent/agent');
    }

    public function testAgentCancelRoute(): void
    {
        $this->assertRouteNot404('POST', '/api/agent/agentCancel');
    }

    public function testConfigRoute(): void
    {
        $this->assertRouteNot404('POST', '/api/agent/config');
    }

    public function testFeedbackRoute(): void
    {
        $this->assertRouteNot404('POST', '/api/agent/feedback');
    }

    /**
     * 验证通配路由命中 AgentController 后，未知方法返回 404（对照组）
     *
     * 开源版通配路由会命中所有 3 段路径；当控制器存在但方法不存在时，
     * 分发器返回 HTTP 404（而非抛 HttpNotFoundException）。
     */
    public function testUnknownAgentMethodReturns404(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/agent/nonexistent');
        $response = $this->app->handle($request);
        $this->assertEquals(
            404,
            $response->getStatusCode(),
            '未知方法应返回 404'
        );
    }

    /** 验证未知控制器返回 404 */
    public function testUnknownControllerReturns404(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/nonexistentCtrl/foo/bar');
        $response = $this->app->handle($request);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /** config 端点带合法参数时返回 JSON 含 error_code 字段 */
    public function testConfigReturnsValidJson(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/agent/config')
            ->withHeader('Content-Type', 'application/json');
        $request = $request->withParsedBody(['item_id' => 0]);

        $response = $this->app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        // 游客访问全局（item_id=0）会返回"请先登录"
        // 但不管怎样，应该有 error_code 字段
        $this->assertArrayHasKey('error_code', $body);
    }

    // ==================================================================
    //  辅助方法
    // ==================================================================

    /**
     * 断言请求路由后状态码不是 404
     */
    private function assertRouteNot404(string $method, string $path): void
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        $response = $this->app->handle($request);
        $status = $response->getStatusCode();
        $this->assertNotEquals(
            404,
            $status,
            "Route {$method} {$path} returned 404 (route not registered)"
        );
    }

    /**
     * 复制 index.php 的约定式通配路由分发器，创建 Slim App
     */
    private function createApp(): \Slim\App
    {
        $container = new Container();
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        // 不设置 basePath，直接用 /api/agent/* 匹配路由
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();

        // 约定式通配路由（与 index.php 完全一致）
        $app->any('/{module}/{controller}/{action}[/{params:.*}]', function (Request $request, Response $response, array $args) use ($container) {
            $module = ucfirst(strtolower($args['module'] ?? ''));

            // 控制器名称转换：支持下划线命名和驼峰命名
            $controllerName = $args['controller'] ?? '';
            if (strpos($controllerName, '_') !== false) {
                // 下划线命名：page_comment -> PageComment
                $controller = str_replace('_', '', ucwords(strtolower($controllerName), '_'));
            } else {
                // 驼峰命名或全小写：publicSquare -> PublicSquare, user -> User
                $normalized = preg_replace('/([a-z])([A-Z])/', '$1_$2', $controllerName);
                $controller = str_replace('_', '', ucwords(strtolower($normalized), '_'));
            }

            $action = strtolower($args['action'] ?? '');

            if ($module === '' || $controller === '' || $action === '') {
                $response->getBody()->write(json_encode([
                    'error_code'    => 10400,
                    'error_message' => 'Invalid route',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // 解析路径参数（key/value 成对）
            if (!empty($args['params'])) {
                $parts = array_values(array_filter(explode('/', $args['params'])));
                $count = count($parts);
                for ($i = 0; $i + 1 < $count; $i += 2) {
                    $key   = $parts[$i];
                    $value = $parts[$i + 1];
                    $request = $request->withAttribute($key, $value);
                }
            }

            $className = "\\App\\{$module}\\Controller\\{$controller}Controller";
            if (!class_exists($className)) {
                $response->getBody()->write(json_encode([
                    'error_code'    => 10404,
                    'error_message' => 'Invalid request',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $controllerInstance = $container->get($className);
            if (!method_exists($controllerInstance, $action)) {
                $response->getBody()->write(json_encode([
                    'error_code'    => 10404,
                    'error_message' => 'Invalid request',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            return $controllerInstance->$action($request, $response);
        });

        return $app;
    }
}
