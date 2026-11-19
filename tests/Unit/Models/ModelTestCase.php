<?php

namespace Tests\Unit\Models;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class ModelTestCase extends TestCase
{
    private ?ConnectionResolverInterface $previousResolver;

    private ?Container $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousResolver = Model::getConnectionResolver();
        $this->previousFacadeApplication = Facade::getFacadeApplication();

        $connection = new MySqlConnection(
            static function (): never {
                throw new RuntimeException(
                    'モデルのUnit TestではDB接続を許可しません。'
                );
            },
            'testing'
        );

        $resolver = new ConnectionResolver([
            'mysql' => $connection,
        ]);

        $resolver->setDefaultConnection('mysql');

        Model::setConnectionResolver($resolver);

        $container = new Container;

        $container->instance('config', new Repository([
            'hashing' => [
                'driver' => 'bcrypt',
                'bcrypt' => ['rounds' => 4],
            ],
        ]));

        $container->instance('hash', new HashManager($container));

        Facade::setFacadeApplication($container);
        Hash::clearResolvedInstance('hash');
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($this->previousResolver);
        }

        Hash::clearResolvedInstance('hash');
        Facade::setFacadeApplication($this->previousFacadeApplication);

        parent::tearDown();
    }
}
