<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if (! $app->environment('testing')) {
            throw new \RuntimeException('テスト環境以外では実行できません。');
        }

        $connection = $app->make('db')->connection();

        if (
            $connection->getDriverName() !== 'mysql'
            || $connection->selectOne('SELECT DATABASE() AS db')->db !== 'testing'
        ) {
            throw new \RuntimeException('接続先がテスト用DBではありません。');
        }

        return $app;
    }
}
