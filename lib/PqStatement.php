<?php

namespace Amp\Postgres;

use Amp\{ Coroutine, function rethrow };
use Interop\Async\Promise;
use pq;

class PqStatement implements Statement {
    /** @var \pq\Statement */
    private $statement;

    /** @var callable */
    private $execute;

    /**
     * @param \pq\Statement $statement
     * @param callable $execute
     */
    public function __construct(pq\Statement $statement, callable $execute) {
        $this->statement = $statement;
        $this->execute = $execute;
    }
    
    public function __destruct() {
        rethrow(new Coroutine(($this->execute)([$this->statement, "deallocateAsync"])));
    }
    
    /**
     * @return string
     */
    public function getQuery(): string {
        return $this->statement->query;
    }

    /**
     * @param mixed ...$params
     *
     * @return \Interop\Async\Promise<\Amp\Postgres\Result>
     *
     * @throws \Amp\Postgres\FailureException If executing the statement fails.
     */
    public function execute(...$params): Promise {
        return new Coroutine(($this->execute)([$this->statement, "execAsync"], $params));
    }
}