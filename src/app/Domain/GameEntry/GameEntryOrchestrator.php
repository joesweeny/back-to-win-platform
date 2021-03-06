<?php

namespace BackToWin\Domain\GameEntry;

use BackToWin\Domain\Game\Entity\Game;
use BackToWin\Domain\Game\Enum\GameStatus;
use BackToWin\Domain\GameEntry\Entity\GameEntry;
use BackToWin\Domain\GameEntry\Exception\GameEntryException;
use BackToWin\Domain\GameEntry\Persistence\Repository;
use BackToWin\Domain\User\Entity\User;
use BackToWin\Domain\User\UserOrchestrator;
use BackToWin\Framework\DateTime\Clock;
use BackToWin\Framework\Uuid\Uuid;

class GameEntryOrchestrator
{
    /**
     * @var Repository
     */
    private $repository;
    /**
     * @var UserOrchestrator
     */
    private $userOrchestrator;
    /**
     * @var Clock
     */
    private $clock;

    public function __construct(Repository $repository, UserOrchestrator $userOrchestrator, Clock $clock)
    {
        $this->repository = $repository;
        $this->userOrchestrator = $userOrchestrator;
        $this->clock = $clock;
    }

    /**
     * @param Game $game
     * @param User $user
     * @throws GameEntryException
     * @return void
     */
    public function addGameEntry(Game $game, User $user): void
    {
        $this->repository->insert($game->getId(), $user->getId());
    }

    /**
     * @param Uuid $gameId
     * @return array|User[]
     */
    public function getUsersForGame(Uuid $gameId): array
    {
        return array_map(function (GameEntry $entry) {
            return $this->userOrchestrator->getUserById($entry->getUserId());
        }, $this->repository->get($gameId));
    }

    /**
     * Check whether a User can enter a Game
     *
     * @param Game $game
     * @param Uuid $userId
     * @throws GameEntryException
     * @return void
     */
    public function checkEntryEligibility(Game $game, Uuid $userId): void
    {
        $this->checkCapacity($game);

        $this->checkStartDateTime($game);

        $this->checkGameStatus($game);

        if ($this->isUserInGame($game, $userId)) {
            throw new GameEntryException('User has already entered Game');
        }
    }

    /**
     * @param Game $game
     * @param Uuid $userId
     * @return bool
     */
    public function isUserInGame(Game $game, Uuid $userId): bool
    {
        foreach ($this->repository->get($game->getId()) as $entry) {
            if ((string) $entry->getUserId() === (string) $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Game $game
     * @throws GameEntryException
     * @return void
     */
    private function checkCapacity(Game $game): void
    {
        if (count($this->repository->get($game->getId())) >= $game->getPlayers()) {
            throw new GameEntryException('Game has reached full capacity');
        }
    }

    private function checkStartDateTime(Game $game): void
    {
        if ($game->getStartDateTime() < $this->clock->now()) {
            throw new GameEntryException('Game has already started');
        }
    }

    private function checkGameStatus(Game $game): void
    {
        if (!$game->getStatus()->equals(GameStatus::CREATED())) {
            throw new GameEntryException('Game does not have the correct status to enter');
        }
    }
}
