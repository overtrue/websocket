<?php

/*
 * This file is part of the overtrue/websocket.
 *
 * (c) overtrue <anzhengchao@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled.
 */

namespace Overtrue\WebSocket;

use Overtrue\WebSocket\Exceptions\ConnectionException;
use Overtrue\WebSocket\Exceptions\InvalidUriException;

/**
 * Class Client.
 */
class Client extends WebSocket
{
    /**
     * @var string
     */
    protected $uri;

    /**
     * Client constructor.
     *
     * @param string $uri
     * @param array  $options
     */
    public function __construct(string $uri, array $options = [])
    {
        if (false === \strpos($uri, '://')) {
            $uri = 'ws://'.$uri;
        } elseif (0 !== \strpos($uri, 'ws://') && 0 !== \strpos($uri, 'wss://')) {
            return new InvalidUriException(\sprintf('Given URI "%s" is invalid', $uri));
        }

        $this->uri = $uri;
        $this->options = \array_merge($this->options, $options);
    }

    /**
     * @param string $payload
     * @param string $opcode
     * @param bool   $masked
     *
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     * @throws \Overtrue\WebSocket\Exceptions\InvalidOpcodeException
     */
    public function send(string $payload, string $opcode = 'text', bool $masked = true)
    {
        if (!$this->connected) {
            $this->connect();
        }

        parent::send($payload, $opcode, $masked);
    }

    /**
     * @param bool $try
     *
     * @return bool|string|null
     *
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     * @throws \Overtrue\WebSocket\Exceptions\InvalidOpcodeException
     */
    public function receive(bool $try = false)
    {
        if (!$this->connected) {
            $this->connect();
        }

        return parent::receive($try);
    }

    /**
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     */
    public function connect()
    {
        $segments = \parse_url($this->uri);
        $scheme = 'wss' === $segments['scheme'] ? 'ssl' : 'tcp';
        $segments['port'] = $segments['port'] ?? ('wss' === $segments['scheme'] ? 443 : 80);
        $url = \sprintf('%s://%s:%s', $scheme, $segments['host'], $segments['port']);

        $this->socket = @\stream_socket_client($url, $errno, $errorMessage, $this->options['timeout'], \STREAM_CLIENT_CONNECT);

        if (!$this->socket) {
            throw new ConnectionException(\sprintf('Unable to connect to socket "%s": [%s]%s', $this->uri, $errno, $errorMessage));
        }

        stream_set_timeout($this->socket, $this->options['timeout']);

        $this->performHandshake();

        stream_set_blocking($this->socket, false);

        $this->connected = true;
    }

    /**
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     */
    protected function performHandshake()
    {
        $key = base64_encode(\substr(md5(time().mt_rand(0, 100)), 0, 16));
        $segments = array_merge([
            'path' => '/',
            'query' => '',
            'fragment' => '',
            'user' => '',
            'pass' => '',
        ], \parse_url($this->uri));

        $segments['port'] = $segments['port'] ?? ('wss' === $segments['scheme'] ? 443 : 80);
        $pathWithQuery = $segments['path'];

        if (!empty($segments['query'])) {
            $pathWithQuery .= '?'.$segments['query'];
        }

        if (!empty($segments['fragment'])) {
            $pathWithQuery .= '#'.$segments['fragment'];
        }

        $headers = [
            "GET {$pathWithQuery} HTTP/1.1",
            "Host: {$segments['host']}:{$segments['port']}",
            'User-Agent: websocket-client-php',
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
            "\r\n",
        ];

        if (!empty($this->options['origin'])) {
            $headers[] = "Sec-WebSocket-Origin: {$this->options['origin']}";
        }

        if ($segments['user'] || $segments['pass']) {
            $headers['Authorization'] = 'Basic '.base64_encode($segments['user'].':'.$segments['pass']);
        }

        if (isset($this->options['headers'])) {
            $headers = array_merge($headers, $this->options['headers']);
        }

        @\fwrite($this->socket, \join("\r\n", $headers));

        $response = \stream_socket_recvfrom($this->socket, 1024);

        preg_match('#Sec-WebSocket-Accept:\s(.*)$#mU', $response, $matches);

        if ($matches) {
            if (trim($matches[1]) !== base64_encode(pack('H*', sha1($key.self::KEY_SALT)))) {
                throw new ConnectionException(\sprintf('Unable to upgrade to socket "%s"', $this->uri));
            }
        }
    }
}
