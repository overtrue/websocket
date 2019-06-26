<?php

/*
 * This file is part of the overtrue/websocket.
 *
 * (c) overtrue <anzhengchao@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled.
 */

namespace Overtrue\WebSocket;

use Overtrue\WebSocket\Exceptions\BadRequestException;
use Overtrue\WebSocket\Exceptions\ConnectionException;

/**
 * Class Server.
 */
class Server extends WebSocket
{
    /**
     * @var resource
     */
    protected $listening;

    /**
     * @var array
     */
    protected $request;

    /**
     * Server constructor.
     *
     * @param array $options
     *
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     */
    public function __construct(array $options = [])
    {
        $this->options = \array_merge($this->options, $options);

        $port = $this->options['port'] ?? 8000;

        do {
            $this->listening = @stream_socket_server("tcp://0.0.0.0:$port", $errno, $message);
        } while (false === $this->listening && $this->port++ < 10000);

        if (!$this->listening) {
            throw new ConnectionException('No valid port to listen.');
        }
    }

    /**
     * @param string $header
     *
     * @return string|null
     */
    public function getHeader(string $header)
    {
        foreach ($this->request as $row) {
            if (false !== stripos($row, $header)) {
                list($name, $value) = explode(':', $row);

                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return bool|resource
     *
     * @throws \Overtrue\WebSocket\Exceptions\BadRequestException
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     */
    public function accept()
    {
        if (empty($this->options['timeout'])) {
            $this->socket = stream_socket_accept($this->listening);
        } else {
            $this->socket = stream_socket_accept($this->listening, $this->options['timeout']);
            stream_set_timeout($this->socket, $this->options['timeout']);
        }

        $this->performHandshake();

        stream_set_blocking($this->socket, false);

        return $this->socket;
    }

    /**
     * @throws \Overtrue\WebSocket\Exceptions\BadRequestException
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     */
    protected function performHandshake()
    {
        $body = '';
        do {
            $buffer = stream_get_line($this->socket, 1024, "\r\n");
            $body .= $buffer."\n";
            $metadata = stream_get_meta_data($this->socket);
        } while (!feof($this->socket) && $metadata['unread_bytes'] > 0);

        if (!preg_match('/GET (.*) HTTP\//mUi', $body, $matches)) {
            throw new BadRequestException('Invalid Request headers.');
        }

        $this->request = explode("\n", $body);

        if (!preg_match('#Sec-WebSocket-Key:\s(.*)$#mUi', $body, $matches)) {
            throw new BadRequestException('No key found in upgrade request');
        }

        $key = trim($matches[1]);

        // @todo Validate key length and base 64...
        $responseKey = base64_encode(pack('H*', sha1($key.self::KEY_SALT)));

        $headers = [
            'HTTP/1.1 101 Switching Protocols',
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Accept: $responseKey",
            "\r\n",
        ];

        $this->write(\join("\r\n", $headers));
    }
}
