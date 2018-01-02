<?php

namespace Amp\Postgres;

use Amp\Promise;

interface Pool extends Link {
    /**
     * Extracts an idle connection from the pool. The connection is completely removed from the pool and cannot be
     * put back into the pool. Useful for operations where connection state must be changed.
     *
     * @return \Amp\Promise<\Amp\Mysql\Connection>
     */
    public function extractConnection(): Promise;

    /**
     * @return int Current number of connections in the pool.
     */
    public function getConnectionCount(): int;

    /**
     * @return int Current number of idle connections in the pool.
     */
    public function getIdleConnectionCount(): int;

    /**
     * @return int Maximum number of connections.
     */
    public function getMaxConnections(): int;

    /**
     * @param bool $reset True to automatically RESET ALL on connections in the pool before using them.
     */
    public function resetConnections(bool $reset);

    /**
     * @param int $timeout Number of seconds before idle connections are removed from the pool. Use 0 for no timeout.
     */
    public function setIdleTimeout(int $timeout);
}
