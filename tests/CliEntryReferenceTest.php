<?php

declare(strict_types=1);

namespace app\tests;

use PHPUnit\Framework\TestCase;

/**
 * 「文档/镜像里写的 CLI 入口，是不是真存在」的防回归扫描。
 *
 * 起因：骨架 v1.2.0 删掉了 bin/ 目录，模板与 Dockerfile 里的 `bin/kode` 文案却一路留到
 * v1.3.8——其中 `ENTRYPOINT ["php","bin/kode","serve"]` 不是注释问题，是构建出来的镜像
 * 必然起不来（Could not open input file）。同类失配（子命令不存在、默认 DB 驱动没装）
 * 都在这里钉住：它们只能靠交叉核对发现，运行期测试反而看不见。
 */
final class CliEntryReferenceTest extends TestCase
{
    /** 随包分发的目录（模板文件会进用户项目，文案必须自洽）。 */
    private const SCANNED_DIRS = ['app', 'config', 'database', 'lang', 'public', 'deploy', 'tests'];

    private const SCANNED_FILES = [
        'Dockerfile', 'kode', 'composer.json', '.env.example', 'phpunit.xml',
        '.gitattributes', '.gitignore', '.dockerignore',
    ];

    /** 幻影入口：bin/kode 自骨架 v1.2.0 起不存在（README 散文里作为历史说明可保留）。 */
    public function testShippedTreeNeverPointsAtTheRemovedBinEntry(): void
    {
        $root = dirname(__DIR__);
        $offenders = [];

        foreach (self::SCANNED_DIRS as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            foreach ($this->filesIn($root . '/' . $dir) as $path) {
                $this->collectPhantomRefs($path, str_replace($root . '/', '', $path), $offenders);
            }
        }
        foreach (self::SCANNED_FILES as $file) {
            if (is_file($root . '/' . $file)) {
                $this->collectPhantomRefs($root . '/' . $file, $file, $offenders);
            }
        }

        // Dockerfile 与 kode 薄壳的注释里允许点名旧入口讲历史（一处是被修掉的镜像故障、
        // 一处是被删掉的死回退）；「真入口是什么」由下面两个测试正面钉住，不靠字符串扫描。
        $offenders = array_values(array_diff($offenders, ['Dockerfile', 'kode']));

        self::assertSame([], $offenders, '以下文件把用户指向已不存在的 bin/kode');
    }

    /** README 的示例输出（代码块内）不得引用旧入口，也不能再打印过时的横幅首行。 */
    public function testReadmeSamplesMatchTheActualCli(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        $fences = [];
        preg_match_all('/```[a-z]*\R(.*?)\R```/s', $readme, $fences);

        $offenders = [];
        foreach ($fences[1] ?? [] as $block) {
            if (str_contains($block, 'bin/kode')) {
                $offenders[] = mb_substr($block, 0, 40);
            }
        }
        self::assertSame([], $offenders, 'README 代码块里出现了不存在的入口');

        // 横幅首行的脚本名由框架写死为 Kode[kode]（HttpServer::renderBanner），示例要跟得上。
        $this->assertStringContainsString('Kode[kode] start in', $readme);
    }

    /** 镜像入口：ENTRYPOINT 指向的文件必须存在，子命令必须在框架 CLI 的命令表里。 */
    public function testDockerfileEntryPointResolvesToARealCliAndCommand(): void
    {
        $root = dirname(__DIR__);
        $dockerfile = (string) file_get_contents($root . '/Dockerfile');

        $this->assertMatchesRegularExpression('/^ENTRYPOINT\s+\[.*\]$/m', $dockerfile);
        preg_match('/^ENTRYPOINT\s+(.*)$/m', $dockerfile, $line);

        /** @var list<string> $argv */
        $argv = json_decode((string) ($line[1] ?? ''), true);
        self::assertIsArray($argv, 'ENTRYPOINT 必须是 exec 形式的 JSON 数组');
        self::assertSame('php', $argv[0] ?? null);
        self::assertSame('kode', $argv[1] ?? null, '镜像入口应是项目根的 kode 薄壳');
        self::assertFileExists($root . '/kode');

        $subcommand = $argv[2] ?? null;
        self::assertIsString($subcommand, 'ENTRYPOINT 要自带启动子命令，别把语义藏在 CMD 里');

        $cli = (string) file_get_contents($root . '/vendor/kode/framework/kode');
        self::assertMatchesRegularExpression(
            "/^\s+'{$subcommand}'\s*=>/m",
            $cli,
            "ENTRYPOINT 用的 `kode {$subcommand}` 不在框架 CLI 命令表里"
        );
    }

    /** 薄壳转发目标必须是装包后真实存在的框架 CLI（曾并列一个无对应文件的 bin 回退）。 */
    public function testThinShellForwardsToTheInstalledFrameworkCli(): void
    {
        $root = dirname(__DIR__);
        $shell = (string) file_get_contents($root . '/kode');

        self::assertStringContainsString("'/vendor/kode/framework/kode'", $shell);
        // 曾经并列的第二候选：框架包里没有这个文件，留着只会让人误以为存在 bin/ 入口。
        self::assertStringNotContainsString('framework/bin/kode', $shell);
        self::assertFileExists($root . '/vendor/kode/framework/kode');
    }

    /** 默认 DB 连接是 pgsql，镜像就得真的装 pdo_pgsql，否则首个 DB 调用 "could not find driver"。 */
    public function testDockerfileInstallsThePdoDriverForTheDefaultConnection(): void
    {
        $root = dirname(__DIR__);
        $database = (string) file_get_contents($root . '/config/database.php');

        preg_match("/'default'\s*=>\s*env\('DB_CONNECTION',\s*'(\w+)'\)/", $database, $default);
        self::assertIsArray($default, 'config/database.php 的 default 连接写法变了，本测试需同步');

        $required = 'pdo_' . (string) $default[1];
        $dockerfile = (string) file_get_contents($root . '/Dockerfile');

        preg_match('/docker-php-ext-install\s+(.+)/', $dockerfile, $exts);
        $installed = preg_split('/\s+/', trim((string) ($exts[1] ?? ''))) ?: [];

        self::assertContains(
            $required,
            $installed,
            "默认连接是 {$default[1]}，镜像却没装 {$required}；构建出的容器一连库就崩"
        );
    }

    /**
     * @param list<string> $offenders
     */
    private function collectPhantomRefs(string $path, string $relative, array &$offenders): void
    {
        // 本测试自己通篇把 bin/kode 当反例文案来讲，不参与扫描。
        if (str_ends_with($relative, 'tests/CliEntryReferenceTest.php')) {
            return;
        }

        $text = (string) file_get_contents($path);
        // vendor/bin/kode 是 composer 生成的真路径（官方 bin 链接），不在此列。
        if (str_contains(str_replace('vendor/bin/kode', '', $text), 'bin/kode')) {
            $offenders[] = $relative;
        }
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $dir): array
    {
        $out = [];
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $out = array_merge($out, $this->filesIn($path));
                continue;
            }
            // 只扫文本类文件：骨架里有图片/二进制占位。
            if (preg_match('/\.(php|md|json|ya?ml|txt|xml|example|ini|conf)$/i', $item) || !str_contains($item, '.')) {
                $out[] = $path;
            }
        }

        return $out;
    }
}
