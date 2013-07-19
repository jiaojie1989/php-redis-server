<?php

namespace Clue\Redis\React;

use React\Socket\Server as ServerSocket;
use React\Promise\When;
use React\EventLoop\LoopInterface;
use React\SocketClient\ConnectorInterface;
use React\Stream\Stream;
use Clue\Redis\React\Client;
use Clue\Redis\React\Server;
use Clue\Redis\Protocol\Factory as ProtocolFactory;
use InvalidArgumentException;
use BadMethodCallException;

class Factory
{
    private $loop;
    private $connector;

    public function __construct(LoopInterface $loop, ConnectorInterface $connector = null)
    {
        $this->loop = $loop;
        $this->connector = $connector;
    }

    /**
     * create redis client connected to address of given redis instance
     *
     * @param string|null $target
     * @return \React\Promise\PromiseInterface resolves with Client or rejects with \Exception
     */
    public function createClient($target = null)
    {
        $that = $this;
        $auth = $this->getAuthFromTarget($target);
        $db   = $this->getDatabaseFromTarget($target);

        return $this->connect($target)->then(function (Stream $stream) use ($that, $auth, $db) {
            $client = new Client($stream, $that->createProtocol());

            return When::all(
                array(
                    ($auth !== null ? $client->auth($auth) : null),
                    ($db   !== null ? $client->select($db) : null)
                ),
                function() use ($client) {
                    return $client;
                },
                function($error) use ($client) {
                    $client->close();
                    throw $error;
                }
            );
        });
    }

    public function createServer($address)
    {
        $parts = parse_url($address);
        if ($parts === false || !isset($parts['host']) || !isset($parts['port'])) {
            return When::reject(new InvalidArgumentException('Invalid server address given'));
        }

        $socket = new ServerSocket($this->loop);
        $socket->listen($parts['port'], $parts['host']);

        return When::resolve(new Server($socket, $this->loop));
    }

    public function createProtocol()
    {
        return ProtocolFactory::create();
    }

    private function connect($target)
    {
        if ($target === null) {
            $target = 'tcp://127.0.0.1:6379';
        }

        $parts = parse_url($target);
        if ($parts === false || !isset($parts['host']) || !isset($parts['port'])) {
            return When::reject(new InvalidArgumentException('Invalid target host given'));
        }

        if ($this->connector === null) {
            return When::reject(new BadMethodCallException('No Connector instance given in Factory constructor'));
        }

        return $this->connector->create($parts['host'], $parts['port']);
    }

    private function getAuthFromTarget($target)
    {
        $auth = null;
        $parts = parse_url($target);
        if (isset($parts['user'])) {
            $auth = $parts['user'];
        }
        if (isset($parts['pass'])) {
            $auth .= ':' . $parts['pass'];
        }

        return $auth;
    }

    private function getDatabaseFromTarget($target)
    {
        $db   = null;
        $path = parse_url($target, PHP_URL_PATH);
        if ($path !== null) {
            // skip first slash
            $db = substr($path, 1);
        }

        return $db;
    }
}
