<?php

/*
 * 缓存配置（kode/cache）
 *
 * 支持 file / redis / memory（进程内）/ memcached / apcu / sqlite 等驱动。
 *
 * 键名口径：这里写 `driver`，框架的 CacheServiceProvider 会归一化成 kode/cache
 * 认识的 `type`（脱离框架直接用 CacheManager 时才需要自己写 `type`）。
 * `array` 是 `memory` 的内置别名（kode/cache >= 1.5.1），两种写法等价。
 *
 * 路径一律由 __DIR__ 推出绝对路径：配置加载期 app() 尚未就绪，base_path()/storage_path()
 * 会退化成「相对当前工作目录」，FPM 下（cwd=public/）会把缓存写到 public/storage。
 */

return [
    'default' => env('CACHE_DRIVER', 'file'),

    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => dirname(__DIR__) . '/storage/cache',
        ],
        // 进程内存储：测试 / CLI 一次性场景，不跨进程共享。
        'array' => [
            'driver' => 'array',
        ],
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => (int) env('REDIS_DB', 0),
            'prefix' => 'kode:cache:',
        ],
    ],
];
