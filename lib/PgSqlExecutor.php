<?php declare(strict_types = 1);

namespace Amp\Postgres;

use Amp\{ CallableMaker, Coroutine, Deferred, Postponed, function pipe };
use Interop\Async\{ Loop, Promise };

class PgSqlExecutor implements Executor {
    use CallableMaker;
    
    /** @var resource PostgreSQL connection handle. */
    private $handle;

    /** @var \Amp\Deferred|null */
    private $deferred;

    /** @var string */
    private $poll;

    /** @var string */
    private $await;

    /** @var callable */
    private $executeCallback;
    
    /** @var callable */
    private $createResult;
    
    /** @var \Amp\Postponed[] */
    private $listeners = [];
    
    /** @var callable */
    private $unlisten;
    
    /**
     * Connection constructor.
     *
     * @param resource $handle PostgreSQL connection handle.
     * @param resource $socket PostgreSQL connection stream socket.
     */
    public function __construct($handle, $socket) {
        $this->handle = $handle;

        $deferred = &$this->deferred;
        $listeners = &$this->listeners;
        
        $this->poll = Loop::onReadable($socket, static function ($watcher) use (&$deferred, &$listeners, $handle) {
            $status = \pg_consume_input($handle);
            
            while ($result = \pg_get_notify($handle, \PGSQL_ASSOC)) {
                $channel = $result["message"];
                if (isset($listeners[$channel])) {
                    $notification = new Notification;
                    $notification->channel = $channel;
                    $notification->pid = $result["pid"];
                    $notification->payload = $result["payload"];
                    $listeners[$channel]->emit($notification);
                }
            }
            
            if ($deferred === null) {
                return; // No active query, only notification listeners.
            }
            
            if (!$status) {
                Loop::disable($watcher);
                $deferred->fail(new FailureException(\pg_last_error($handle)));
                return;
            }

            if (!\pg_connection_busy($handle)) {
                if (empty($listeners)) {
                    Loop::disable($watcher);
                }
                $deferred->resolve(\pg_get_result($handle));
            }
        });

        $this->await = Loop::onWritable($socket, static function ($watcher) use (&$deferred, $handle) {
            $flush = \pg_flush($handle);
            if (0 === $flush) {
                return; // Not finished sending data, listen again.
            }
            
            Loop::disable($watcher);

            if ($flush === false) {
                $deferred->fail(new FailureException(\pg_last_error($handle)));
            }
        });
        
        Loop::disable($this->poll);
        Loop::disable($this->await);

        $this->createResult = $this->callableFromInstanceMethod("createResult");
        $this->executeCallback = $this->callableFromInstanceMethod("sendExecute");
        $this->unlisten = $this->callableFromInstanceMethod("unlisten");
    }

    /**
     * Frees Io watchers from loop.
     */
    public function __destruct() {
        if (\is_resource($this->handle)) {
            \pg_close($this->handle);
        }
        
        Loop::cancel($this->poll);
        Loop::cancel($this->await);
    }

    /**
     * @coroutine
     *
     * @param callable $function Function name to execute.
     * @param mixed ...$args Arguments to pass to function.
     *
     * @return \Generator
     *
     * @resolve resource
     *
     * @throws \Amp\Postgres\FailureException
     */
    private function send(callable $function, ...$args): \Generator {
        while ($this->deferred !== null) {
            try {
                yield $this->deferred->promise();
            } catch (\Throwable $exception) {
                // Ignore failure from another operation.
            }
        }

        $result = $function($this->handle, ...$args);

        if ($result === false) {
            throw new FailureException(\pg_last_error($this->handle));
        }

        $this->deferred = new Deferred;

        Loop::enable($this->poll);
        if (0 === $result) {
            Loop::enable($this->await);
        }

        try {
            $result = yield $this->deferred->promise();
        } finally {
            $this->deferred = null;
        }

        return $result;
    }
    
    /**
     * @param resource $result PostgreSQL result resource.
     *
     * @return \Amp\Postgres\Result
     *
     * @throws \Amp\Postgres\FailureException
     * @throws \Amp\Postgres\QueryError
     */
    private function createResult($result): Result {
        switch (\pg_result_status($result, \PGSQL_STATUS_LONG)) {
            case \PGSQL_EMPTY_QUERY:
                throw new QueryError("Empty query string");

            case \PGSQL_COMMAND_OK:
                return new PgSqlCommandResult($result);

            case \PGSQL_TUPLES_OK:
                return new PgSqlTupleResult($result);

            case \PGSQL_NONFATAL_ERROR:
            case \PGSQL_FATAL_ERROR:
                throw new QueryError(\pg_result_error($result));

            case \PGSQL_BAD_RESPONSE:
                throw new FailureException(\pg_result_error($result));

            default:
                throw new FailureException("Unknown result status");
        }
    }
    
    private function sendExecute(string $name, array $params): Promise {
        return pipe(new Coroutine($this->send("pg_send_execute", $name, $params)), $this->createResult);
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $sql): Promise {
        return pipe(new Coroutine($this->send("pg_send_query", $sql)), $this->createResult);
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $sql, ...$params): Promise {
        return pipe(new Coroutine($this->send("pg_send_query_params", $sql, $params)), $this->createResult);
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(string $sql): Promise {
        return pipe(new Coroutine($this->send("pg_send_prepare", $sql, $sql)), function () use ($sql) {
            return new PgSqlStatement($sql, $this->executeCallback);
        });
    }
    
    /**
     * {@inheritdoc}
     */
    public function notify(string $channel, string $payload = ""): Promise {
        if ($payload === "") {
            return $this->query(\sprintf("NOTIFY %s"));
        }
        
        return $this->query(\sprintf("NOTIFY %s, '%s'", $channel, $payload));
    }
    
    /**
     * {@inheritdoc}
     */
    public function listen(string $channel): Promise {
        return pipe($this->query(\sprintf("LISTEN %s", $channel)), function (CommandResult $result) use ($channel) {
            $postponed = new Postponed;
            $this->listeners[$channel] = $postponed;
            Loop::enable($this->poll);
            return new Listener($postponed->getObservable(), $channel, $this->unlisten);
        });
    }
    
    /**
     * @param string $channel
     *
     * @return \Interop\Async\Promise
     *
     * @throws \Error
     */
    private function unlisten(string $channel): Promise {
        if (!isset($this->listeners[$channel])) {
            throw new \Error("Not listening on that channel");
        }
        
        $postponed = $this->listeners[$channel];
        unset($this->listeners[$channel]);
        
        if (empty($this->listeners) && $this->deferred === null) {
            Loop::disable($this->poll);
        }
        
        $promise = $this->query(\sprintf("UNLISTEN %s", $channel));
        $promise->when(function () use ($postponed) {
            $postponed->resolve();
        });
        return $promise;
    }
}
