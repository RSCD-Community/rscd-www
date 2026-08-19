<?php

namespace RSCD\Util;

/**
 * Client for the login server's frontend query endpoint.
 *
 * The login server binds a plain-text control socket (Config.QUERY_IP:QUERY_PORT,
 * localhost:8181 by default) speaking one request per connection:
 *
 *   "<id> <param> <param> ..."          (each param URL-encoded)
 *
 * answered with the same encoding — "1" on success, "0" on failure, and for
 * LIST_PLAYERS "1 <hash,x,y,world> ..." — after which the server closes the
 * connection (FConnectionHandler.messageSent).
 *
 * The endpoint is deliberately unauthenticated (it predates this site and
 * other tools may speak it), so it must only ever be reachable from the web
 * host itself or over a firewalled link. Configure the target with the
 * "gameServer" property in app.json: {"host": "...", "port": 8181}. When the
 * socket is unreachable every call returns false/null and the admin pages
 * degrade to their database-only capabilities.
 */
class WorldControl {

    /* Request ids, from the legacy frontend protocol (ls/packethandler/frontend). */
    const LOGOUT       = 1;
    const SHUTDOWN     = 2;
    const UPDATE       = 3;
    const GLOBAL_MSG   = 5;
    const ALERT        = 6;
    const LIST_PLAYERS = 7;

    /** @var string */
    protected $host;

    /** @var int */
    protected $port;

    /** @var int Connect/read timeout in seconds. */
    protected $timeout;

    /**
     * @param string $host    Login server host.
     * @param int    $port    Frontend query port.
     * @param int    $timeout Connect/read timeout in seconds.
     */
    public function __construct($host = '127.0.0.1', $port = 8181, $timeout = 3) {
        $this->host = (string)$host;
        $this->port = (int)$port;
        $this->timeout = (int)$timeout;
    }

    /**
     * Build a client from the application config's "gameServer" property,
     * falling back to localhost:8181 when it is absent.
     *
     * @param  object $state Application state.
     * @return static
     */
    public static function fromConfig($state) {
        $config = $state->config->getProperty('gameServer');
        $host = !empty($config->host) ? (string)$config->host : '127.0.0.1';
        $port = !empty($config->port) ? (int)$config->port : 8181;
        return new static($host, $port);
    }

    /**
     * Send one request and return the decoded response parameters.
     *
     * @param  int        $id     Request id.
     * @param  string[]   $params Request parameters (URL-encoded on the wire).
     * @return array|null         ['id' => int, 'params' => string[]], or null
     *                            when the socket is unreachable or the reply
     *                            is malformed.
     */
    public function send($id, array $params = []) {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if(!$socket) {
            return null;
        }
        stream_set_timeout($socket, $this->timeout);

        $line = (string)(int)$id;
        foreach($params as $param) {
            $line .= ' ' . urlencode((string)$param);
        }
        @fwrite($socket, $line);

        // The server writes one response and closes; a read timeout guards
        // against a hung connection keeping the admin page open forever.
        $buffer = '';
        while(!feof($socket)) {
            $chunk = @fread($socket, 1024);
            $meta = stream_get_meta_data($socket);
            if($chunk === false || $meta['timed_out']) {
                break;
            }
            $buffer .= $chunk;
        }
        @fclose($socket);

        $buffer = trim($buffer);
        if($buffer === '' || !ctype_digit(substr($buffer, 0, 1))) {
            return null;
        }
        $parts = explode(' ', $buffer);
        $responseId = (int)array_shift($parts);
        return [
            'id' => $responseId,
            'params' => array_map('urldecode', $parts),
        ];
    }

    /**
     * Whether the control socket answers at all (a LIST_PLAYERS probe for
     * world 1 — read-only on the server side).
     *
     * @return bool
     */
    public function reachable() {
        return $this->send(static::LIST_PLAYERS, ['1']) !== null;
    }

    /**
     * Force a character to log out.
     *
     * @param  int|string $usernameHash Base-37 name hash.
     * @return bool
     */
    public function logout($usernameHash) {
        $response = $this->send(static::LOGOUT, [(string)$usernameHash]);
        return !empty($response) && $response['id'] === 1;
    }

    /**
     * Ask the server to save everything and stop as soon as possible.
     *
     * @param  int  $world World id (0 = all, per the legacy protocol).
     * @return bool
     */
    public function shutdown($world = 0) {
        $response = $this->send(static::SHUTDOWN, [(string)(int)$world]);
        return !empty($response) && $response['id'] === 1;
    }

    /**
     * Announce a 60-second update warning to all players, then shut down.
     *
     * @param  string $message Update message shown to players.
     * @return bool
     */
    public function update($message = '') {
        $response = $this->send(static::UPDATE, [(string)$message]);
        return !empty($response) && $response['id'] === 1;
    }

    /**
     * Send a global message to everyone logged in, on every world.
     *
     * @param  string $message
     * @return bool
     */
    public function globalMessage($message) {
        $response = $this->send(static::GLOBAL_MSG, [(string)$message]);
        return !empty($response) && $response['id'] === 1;
    }

    /**
     * Send an alert box to one character.
     *
     * @param  int|string $usernameHash Base-37 name hash.
     * @param  string     $message
     * @return bool
     */
    public function alert($usernameHash, $message) {
        $response = $this->send(static::ALERT, [(string)$usernameHash, (string)$message]);
        return !empty($response) && $response['id'] === 1;
    }

    /**
     * List the players logged in to one world.
     *
     * @param  int        $world World id.
     * @return array|null       Rows of ['user' => string hash, 'x' => int,
     *                          'y' => int, 'world' => int], or null when the
     *                          socket is unreachable or the request failed.
     */
    public function listPlayers($world) {
        $response = $this->send(static::LIST_PLAYERS, [(string)(int)$world]);
        if(empty($response) || $response['id'] !== 1) {
            return null;
        }
        $players = [];
        foreach($response['params'] as $entry) {
            $fields = explode(',', $entry);
            if(count($fields) >= 4) {
                $players[] = [
                    'user'  => $fields[0],
                    'x'     => (int)$fields[1],
                    'y'     => (int)$fields[2],
                    'world' => (int)$fields[3],
                ];
            }
        }
        return $players;
    }

}
