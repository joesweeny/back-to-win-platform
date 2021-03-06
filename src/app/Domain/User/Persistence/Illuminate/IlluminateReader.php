<?php

namespace BackToWin\Domain\User\Persistence\Illuminate;

use BackToWin\Domain\User\Entity\User;
use BackToWin\Domain\User\Persistence\Hydration\Hydrator;
use BackToWin\Domain\User\Persistence\Reader;
use BackToWin\Framework\Exception\NotFoundException;
use BackToWin\Framework\Uuid\Uuid;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

class IlluminateReader implements Reader
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * IlluminateWriter constructor.
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @inheritdoc
     */
    public function getByEmail(string $email): User
    {
        $data = $this->table()->where('email', $email)->get()->first();

        if (!$data) {
            throw new NotFoundException("User with email '{$email}' does not exist");
        }

        return Hydrator::fromRawData($data);
    }

    /**
     * @inheritdoc
     */
    public function getById(Uuid $id): User
    {
        $data = $this->table()->where('id', $id->toBinary())->get()->first();

        if (!$data) {
            throw new NotFoundException("User with ID '{$id}' does not exist");
        }

        return Hydrator::fromRawData($data);
    }

    /**
     * @inheritdoc
     */
    public function getByUsername(string $username): User
    {
        $data = $this->table()->where('username', $username)->get()->first();

        if (!$data) {
            throw new NotFoundException("User with username '{$username}' does not exist");
        }

        return Hydrator::fromRawData($data);
    }

    /**
     * @inheritdoc
     */
    public function getUsers(): array
    {
        return array_map(function ($row) {
            return Hydrator::fromRawData($row);
        }, $this->table()->orderBy('created_at')->get()->toArray());
    }

    /**
     * @return Builder
     */
    private function table(): Builder
    {
        return $this->connection->table('user');
    }
}
