<?php declare(strict_types = 1);

namespace Amp\Postgres;

use Interop\Async\Promise;

interface Statement {
    /**
     * @param mixed ...$params
     *
     * @return \Interop\Async\Promise<\Amp\Postgres\Result>
     */
    public function execute(...$params): Promise;
}
