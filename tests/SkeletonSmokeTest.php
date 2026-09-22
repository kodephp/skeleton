<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Application;
use Kode\Framework\Testing\TestCase;

/**
 * 骨架端到端冒烟测试。
 *
 * 目的有三个：
 *  1. 保证 `composer test` 开箱可用（骨架曾声明了测试命令却没有 tests/ 目录，命令必然失败）；
 *  2. 保证 create-project 拿到的项目能真实引导（容器 + 路由 + 中间件全链路）；
 *  3. 守住版本契约——骨架版本（config app.version）与内核版本（Application::VERSION）
 *     是两个独立演进的号，此处锁定「两者都必须存在且非空」，防止发版时漏改。
 */
final class SkeletonSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    public function test_app_boots(): void
    {
        self::assertInstanceOf(Application::class, $this->app());
    }

    public function test_health_endpoint_is_ok(): void
    {
        $this->get('/health')
            ->assertStatus(200)
            ->assertSee('ok');
    }

    public function test_health_reports_framework_kernel_version(): void
    {
        $payload = $this->get('/health')->json();

        self::assertSame(
            Application::VERSION,
            $payload['version'] ?? null,
            '/health 返回的 version 必须是内核版本常量'
        );
    }

    public function test_ping_endpoint(): void
    {
        $this->get('/ping')->assertStatus(200)->assertSee('pong');
    }

    /**
     * 骨架版本契约：config(app.version) 必须与 composer.json 的 version 同值。
     *
     * 骨架版本、composer.json 的 version、git tag 三者同步是发版约定；此前只断言「非空且像
     * 版本号」，而 phpunit.xml 还注入了 APP_VERSION=1.0.0 覆盖默认值，等于把漂移的三处缩成
     * 一处自证。现在两者直接比对（create-project 若剥掉 version 字段则跳过该项）。
     */
    public function test_skeleton_version_is_configured(): void
    {
        $version = config('app.version');

        self::assertIsString($version);
        self::assertNotSame('', $version, 'config(app.version) 不能为空');
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+/',
            $version,
            'config(app.version) 必须是语义化版本号，当前值：' . $version
        );

        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true
        );
        $declared = is_array($manifest) ? ($manifest['version'] ?? null) : null;
        if (!is_string($declared)) {
            return;
        }

        self::assertSame(
            $declared,
            $version,
            'composer.json 的 version 与 config(app.version) 必须同步（发版三处一致：tag/composer.json/config）'
        );
    }

    public function test_root_route_registered(): void
    {
        $this->get('/')->assertStatus(200);
    }
}
