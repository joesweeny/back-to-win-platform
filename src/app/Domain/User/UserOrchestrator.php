<?php

namespace BackToWin\Domain\User;

use BackToWin\Domain\User\Persistence\Reader;
use BackToWin\Domain\User\Persistence\Writer;
use BackToWin\Domain\User\Entity\User;
use BackToWin\Domain\UserPurse\Entity\UserPurse;
use BackToWin\Domain\UserPurse\UserPurseOrchestrator;
use BackToWin\Framework\Exception\NotAuthenticatedException;
use BackToWin\Framework\Exception\NotFoundException;
use BackToWin\Framework\Exception\UserCreationException;
use BackToWin\Framework\Uuid\Uuid;
use Money\Currency;
use Money\Money;

class UserOrchestrator
{
    /**
     * @var Writer
     */
    private $writer;
    /**
     * @var Reader
     */
    private $reader;
    /**
     * @var UserPurseOrchestrator
     */
    private $purseOrchestrator;

    public function __construct(Writer $writer, Reader $reader, UserPurseOrchestrator $purseOrchestrator)
    {
        $this->writer = $writer;
        $this->reader = $reader;
        $this->purseOrchestrator = $purseOrchestrator;
    }

    /**
     * @param User $user
     * @param Currency $currency
     * @return User
     * @throws UserCreationException
     */
    public function createUser(User $user, Currency $currency): User
    {
        if ($this->userExistsWithEmail($user)) {
            throw new UserCreationException("A user has already registered with this email address {$user->getEmail()}");
        }

        if ($this->userExistsWithUsername($user)) {
            throw new UserCreationException("A user has already registered with this username {$user->getUsername()}");
        }

        $user = $this->writer->insert($user);

        $this->purseOrchestrator->createUserPurse(new UserPurse($user->getId(), new Money(0, $currency)));

        return $user;
    }

    /**
     * @param string $email
     * @return User
     * @throws NotFoundException
     */
    public function getUserByEmail(string $email): User
    {
        return $this->reader->getByEmail($email);
    }

    /**
     * @param Uuid $id
     * @return User
     * @throws \BackToWin\Framework\Exception\NotFoundException
     */
    public function getUserById(Uuid $id): User
    {
        return $this->reader->getById($id);
    }

    /**
     * @param User $user
     * @throws NotFoundException
     * @return User
     */
    public function updateUser(User $user): User
    {
        return $this->writer->update($user);
    }

    /**
     * @param User $user
     * @return void
     */
    public function deleteUser(User $user)
    {
        $this->writer->delete($user);
    }

    public function userExistsWithEmail(User $user): bool
    {
        try {
            $this->getUserByEmail($user->getEmail());
            return true;
        } catch (NotFoundException $e) {
            return false;
        }
    }

    public function userExistsWithUsername(User $user): bool
    {
        try {
            $this->reader->getByUsername($user->getUsername());
            return true;
        } catch (NotFoundException $e) {
            return false;
        }
    }

    /**
     * @param string $email
     * @param string $password
     * @return User
     * @throws NotFoundException
     * @throws NotAuthenticatedException
     */
    public function verifyUser(string $email, string $password): User
    {
        $user = $this->getUserByEmail($email);

        if (!$user->getPasswordHash()->verify($password)) {
            throw new NotAuthenticatedException('Unable to verify User with credentials provided');
        }

        return $user;
    }

    /**
     * @return array
     */
    public function getUsers(): array
    {
        return $this->reader->getUsers();
    }
}
