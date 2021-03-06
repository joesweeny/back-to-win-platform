<?php

namespace BackToWin\Bootstrap;

use Aws\S3\S3Client;
use BackToWin\Domain\Auth\Services\Token\TokenGenerator;
use BackToWin\Domain\Auth\Services\Token\TokenValidator;
use BackToWin\Domain\Bank\Bank;
use BackToWin\Domain\Bank\User\LogBank;
use BackToWin\Domain\Bank\User\RedisBank;
use BackToWin\Domain\GameEntry\Services\EntryFee\EntryFeeStore;
use BackToWin\Domain\GameEntry\Services\EntryFee\Log\LogEntryFeeStore;
use BackToWin\Domain\GameEntry\Services\EntryFee\Redis\RedisEntryFeeStore;
use Chief\Busses\SynchronousCommandBus;
use Chief\CommandBus;
use Chief\Container;
use Chief\Resolvers\NativeCommandHandlerResolver;
use BackToWin\Framework\DateTime\Clock;
use BackToWin\Framework\DateTime\SystemClock;
use DI\ContainerBuilder;
use function DI\object;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use BackToWin\Framework\Middleware\Error\ErrorResponseFactory;
use BackToWin\Framework\Middleware\Error\JsonErrorResponseFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Interop\Container\ContainerInterface;
use BackToWin\Framework\CommandBus\ChiefAdapter;
use BackToWin\Framework\Routing\Router;
use League\Flysystem\Adapter\Local;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ContainerFactory
{
    /** @var Config|null */
    private $config;

    public function create(Config $config = null): ContainerInterface
    {
        $this->config = $config;

        return (new ContainerBuilder)
            ->useAutowiring(true)
            ->ignorePhpDocErrors(true)
            ->useAnnotations(false)
            ->writeProxiesToFile(false)
            ->addDefinitions($this->getDefinitions())
            ->build();
    }

    /**
     * @return array
     * @throws \UnexpectedValueException
     */
    protected function getDefinitions(): array
    {
        return array_merge(
            $this->defineConfig(),
            $this->defineFramework(),
            $this->defineDomain(),
            $this->defineConnections()
        );
    }

    protected function defineConfig(): array
    {
        return [
            Config::class => \DI\factory(function () {
                return $this->config;
            }),
        ];
    }

    /**
     * @return array
     * @throws \UnexpectedValueException
     */
    private function defineFramework(): array
    {
        return [
            ContainerInterface::class => \DI\factory(function (ContainerInterface $container) {
                return $container;
            }),


            Router::class => \DI\decorate(function (Router $router, ContainerInterface $container) {
                return $router
                    ->addRoutes($container->get(\BackToWin\Application\Http\Api\v1\Routing\OpenApi\RouteManager::class))
                    ->addRoutes($container->get(\BackToWin\Application\Http\Api\v1\Routing\User\RouteManager::class))
                    ->addRoutes($container->get(\BackToWin\Application\Http\Api\v1\Routing\UserPurse\RouteManager::class))
                    ->addRoutes($container->get(\BackToWin\Application\Http\Api\v1\Routing\Game\RouteManager::class))
                    ->addRoutes($container->get(\BackToWin\Application\Http\Api\v1\Routing\Auth\RouteManager::class))
                    ->addRoutes($container->get(\BackToWin\Application\Http\Api\v1\Routing\Avatar\RouteManager::class));
            }),

            CommandBus::class => \DI\factory(function (ContainerInterface $container) {
                $bus = new ChiefAdapter(new SynchronousCommandBus(new NativeCommandHandlerResolver(new class($container) implements Container {
                    /**
                     * @var ContainerInterface
                     */
                    private $container;
                    public function __construct(ContainerInterface $container)
                    {
                        $this->container = $container;
                    }
                    public function make($class)
                    {
                        return $this->container->get($class);
                    }
                })));

                return $bus;
            }),

            LoggerInterface::class => \DI\factory(function (ContainerInterface $container) {
                switch ($logger = $container->get(Config::class)->get('log.logger')) {
                    case 'monolog':
                        $logger = new Logger('error');
                        $logger->pushHandler(new ErrorLogHandler);
                        return $logger;

                    case 'null':
                        return new NullLogger;

                    default:
                        throw new \UnexpectedValueException("Logger '$logger' not recognised");
                }
            }),

            Clock::class => \DI\object(SystemClock::class),

            Client::class => \DI\factory(function (ContainerInterface $container) {
                $config = $container->get(Config::class);

                return new Client($config->get('redis.default'));
            }),

            Bank::class => \DI\factory(function (ContainerInterface $container) {
                switch ($bank = $container->get(Config::class)->get('bank.user.driver')) {
                    case 'redis':
                        return new RedisBank($container->get(Client::class));
                    case 'log':
                        return new LogBank($container->get(LoggerInterface::class));
                    default:
                        throw new \UnexpectedValueException("Bank '$bank' not recognised");
                }
            }),

            \BackToWin\Domain\Admin\Bank\Bank::class => \DI\factory(function (ContainerInterface $container) {
                switch ($bank = $container->get(Config::class)->get('bank.admin.driver')) {
                    case 'redis':
                        return new \BackToWin\Domain\Admin\Bank\Redis\RedisBank($container->get(Client::class));
                    case 'log':
                        return new \BackToWin\Domain\Admin\Bank\Log\LogBank($container->get(LoggerInterface::class));
                    default:
                        throw new \UnexpectedValueException("Admin bank '$bank' not recognised");
                }
            }),

            EntryFeeStore::class => \DI\factory(function (ContainerInterface $container) {
                switch ($store = $container->get(Config::class)->get('bank.entry-fee.driver')) {
                    case 'redis':
                        return new RedisEntryFeeStore($container->get(Client::class));
                    case 'log':
                        return new LogEntryFeeStore($container->get(LoggerInterface::class));
                    default:
                        throw new \UnexpectedValueException("Entry fee store '$store' not recognised");
                }
            }),

            ErrorResponseFactory::class => \DI\object(JsonErrorResponseFactory::class),

            TokenGenerator::class => \DI\factory(function (ContainerInterface $container) {
                switch ($driver = $container->get(Config::class)->get('auth.token.driver')) {
                    case 'jwt':
                        return $container->get(\BackToWin\Domain\Auth\Services\Token\Jwt\JwtTokenGenerator::class);
                    default:
                        throw new \UnexpectedValueException("Auth token driver '$driver' not recognised");
                }
            }),

            TokenValidator::class => \DI\factory(function (ContainerInterface $container) {
                switch ($driver = $container->get(Config::class)->get('auth.token.driver')) {
                    case 'jwt':
                        return $container->get(\BackToWin\Domain\Auth\Services\Token\Jwt\JwtTokenValidator::class);
                    default:
                        throw new \UnexpectedValueException("Auth token driver '$driver' not recognised");
                }
            }),

            Filesystem::class => \DI\factory(function (ContainerInterface $container) {
                $config = $container->get(Config::class);

                switch ($driver = $config->get('storage.driver')) {
                    case 'S3':
                        $client = S3Client::factory([
                            'credentials' => [
                                'key'    => $config->get('storage.aws.key'),
                                'secret' => $config->get('storage.aws.secret'),
                            ],
                            'region' => 'eu-west-2',
                            'version' => 'latest',
                        ]);

                        $adapter = new AwsS3Adapter($client, $config->get('storage.aws.s3-bucket'));

                        return new Filesystem($adapter);
                    case 'local':
                        $local = new Local(
                            $config->get('storage.local.path'),
                            0,
                            Local::SKIP_LINKS,
                            [
                                'file' => [
                                    'public' => 0777,
                                    'private' => 0777,
                                ],
                                'dir' => [
                                    'public' => 0777,
                                    'private' => 0777,
                                ]
                            ]
                        );

                        return new Filesystem($local);
                    default:
                        throw new \UnexpectedValueException("Storage driver '$driver' not recognised");
                }
            }),
        ];
    }

    /**
     * @return array
     */
    private function defineDomain(): array
    {
        return [
            \BackToWin\Domain\User\Persistence\Reader::class => \DI\object(\BackToWin\Domain\User\Persistence\Illuminate\IlluminateReader::class),

            \BackToWin\Domain\User\Persistence\Writer::class => \DI\object(\BackToWin\Domain\User\Persistence\Illuminate\IlluminateWriter::class),

            \BackToWin\Domain\UserPurse\Persistence\Writer::class => \DI\object(\BackToWin\Domain\UserPurse\Persistence\Illuminate\IlluminateWriter::class),

            \BackToWin\Domain\UserPurse\Persistence\Reader::class => \DI\object(\BackToWin\Domain\UserPurse\Persistence\Illuminate\IlluminateReader::class),

            \BackToWin\Domain\Game\Persistence\Writer::class => \DI\object(\BackToWin\Domain\Game\Persistence\Illuminate\IlluminateWriter::class),

            \BackToWin\Domain\Game\Persistence\Reader::class => \DI\object(\BackToWin\Domain\Game\Persistence\Illuminate\IlluminateReader::class),

            \BackToWin\Domain\GameEntry\Persistence\Repository::class => \DI\object(\BackToWin\Domain\GameEntry\Persistence\Illuminate\IlluminateRepository::class),

            \BackToWin\Domain\Admin\Bank\Persistence\Repository::class => \DI\object(\BackToWin\Domain\Admin\Bank\Persistence\Illuminate\IlluminateRepository::class),

            \BackToWin\Domain\GameResult\Persistence\Repository::class => \DI\object(\BackToWin\Domain\GameResult\Persistence\Illuminate\IlluminateRepository::class),

            \BackToWin\Domain\Avatar\Persistence\Repository::class => \DI\object(\BackToWin\Domain\Avatar\Persistence\Illuminate\IlluminateDbRepository::class),
        ];
    }


    private function defineConnections()
    {
        return [
            AbstractSchemaManager::class => \DI\factory(function (ContainerInterface $container) {
                return $container->get(Connection::class)->getDoctrineSchemaManager();
            }),

            Connection::class => \DI\factory(function (ContainerInterface $container) {

                $config = $container->get(Config::class);

                $dsn = $config->get('database.default.pdo.dsn');
                
                if (substr($dsn, 0, 5) === 'mysql') {
                    return new MySqlConnection($container->get(\PDO::class));
                }

                if (substr($dsn, 0, 6) === 'sqlite') {
                    return new SQLiteConnection($container->get(\PDO::class));
                }

                throw new \RuntimeException("Unrecognised DNS {$dsn}");
            }),

            \Doctrine\DBAL\Driver\Connection::class => \DI\factory(function (ContainerInterface $container) {
                return $container->get(Connection::class)->getDoctrineConnection();
            }),

            \PDO::class => \DI\factory(function (ContainerInterface $container) {
                $config = $container->get(Config::class);
                $pdo = new \PDO(
                    $config->get('database.default.pdo.dsn'),
                    $config->get('database.default.pdo.user'),
                    $config->get('database.default.pdo.password')
                );
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                return $pdo;
            }),
        ];
    }
}
