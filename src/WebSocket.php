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
use Overtrue\WebSocket\Exceptions\InvalidOpcodeException;

/**
 * Class WebSocket.
 *
 *  (c) Fredrik Liljegren <fredrik.liljegren@textalk.s>
 *
 * This class is based of Textalk/websocket-php:
 * https://github.com/Textalk/websocket-php/blob/master/lib/Base.php
 */
class WebSocket
{
    /**
     * @var resource
     */
    protected $socket;

    /**
     * @var bool
     */
    protected $connected = false;

    /**
     * @var bool
     */
    protected $closing = false;

    /**
     * @var string
     */
    protected $lastOpcode = null;

    /**
     * @var int
     */
    protected $closeStatus = null;

    /**
     * @var string
     */
    protected $hugePayload = '';

    /**
     * @var string
     */
    protected $socketBuffer = '';

    /**
     * @var string
     */
    protected $unparsedFragment = '';

    /**
     * @var array
     */
    protected $options = [
        'timeout' => 5,
        'fragment_size' => 4096,
    ];

    const OPCODES = [
        'continuation' => 0,
        'text' => 1,
        'binary' => 2,
        'close' => 8,
        'ping' => 9,
        'pong' => 10,
    ];

    const FIRST_BYTE_MASK = 0b10001111;
    const SECOND_BYTE_MASK = 0b11111111;

    const FINAL_BIT = 0b10000000;
    const OPCODE_MASK = 0b00001111;

    const MASKED_BIT = 0b10000000;
    const PAYLOAD_LENGTH_MASK = 0b01111111;

    const PAYLOAD_LENGTH_16BIT = 0b01111110;
    const PAYLOAD_LENGTH_64BIT = 0b01111111;

    const KEY_SALT = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function getLastOpcode()
    {
        return $this->lastOpcode;
    }

    public function getCloseStatus()
    {
        return $this->closeStatus;
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return $this->connected;
    }

    /**
     * @param int $timeout
     */
    public function setTimeout(int $timeout)
    {
        $this->options['timeout'] = $timeout;

        if ($this->socket && 'stream' === get_resource_type($this->socket)) {
            stream_set_timeout($this->socket, $timeout);
        }
    }

    /**
     * @param int $size
     *
     * @return $this
     */
    public function setFragmentSize(int $size)
    {
        $this->options['fragment_size'] = $size;

        return $this;
    }

    /**
     * @return int
     */
    public function getFragmentSize()
    {
        return $this->options['fragment_size'];
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
        if (!in_array($opcode, array_keys(self::OPCODES))) {
            throw new InvalidOpcodeException("Invalid opcode '$opcode'.  Try 'text' or 'binary'.");
        }

        // record the length of the payload
        $payloadLength = strlen($payload);

        $fragmentCursor = 0;

        // while we have data to send
        while ($payloadLength > $fragmentCursor) {
            // get a fragment of the payload
            $subPayload = substr($payload, $fragmentCursor, $this->options['fragment_size']);

            // advance the cursor
            $fragmentCursor += $this->options['fragment_size'];

            // is this the final fragment to send?
            $final = $payloadLength <= $fragmentCursor;

            // send the fragment
            $this->sendFragment($subPayload, $opcode, $final, $masked);

            // all fragments after the first will be marked a continuation
            $opcode = 'continuation';
        }
    }

    /**
     * @param string $payload
     * @param string $opcode
     * @param bool   $final
     * @param bool   $masked
     *
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     */
    protected function sendFragment(string $payload, string $opcode, bool $final, bool $masked)
    {
        $frame = [0, 0];

        // Set final bit
        $frame[0] |= self::FINAL_BIT * (bool) $final;
        // Set correct opcode
        $frame[0] |= self::OPCODE_MASK & self::OPCODES[$opcode];
        // Reset reserved bytes
        $frame[0] &= self::FIRST_BYTE_MASK;

        // 7 bits of payload length...
        $payloadLength = strlen($payload);
        if ($payloadLength > 65535) {
            $opcodeLength = self::PAYLOAD_LENGTH_64BIT;
            array_push($frame, pack('J', $payloadLength));
        } elseif ($payloadLength > 125) {
            $opcodeLength = self::PAYLOAD_LENGTH_16BIT;
            array_push($frame, pack('n', $payloadLength));
        } else {
            $opcodeLength = $payloadLength;
        }

        // Set masked mode
        $frame[1] |= self::MASKED_BIT * (bool) $masked;
        $frame[1] |= self::PAYLOAD_LENGTH_MASK & $opcodeLength;

        // Handle masking
        if ($masked) {
            // generate a random mask:
            $mask = '';
            for ($i = 0; $i < 4; ++$i) {
                $mask .= chr(rand(0, 255));
            }
            array_push($frame, $mask);

            for ($i = 0; $i < $payloadLength; ++$i) {
                $payload[$i] = $payload[$i] ^ $mask[$i & 3];
            }
        }

        // Append payload to frame
        array_push($frame, $payload);

        $this->write($frame);
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
        $response = null;

        do {
            $response = $this->receiveFragment();
        } while (is_null($response) && !$try);

        return $response;
    }

    /**
     * @return string|null
     *
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     */
    protected function receiveFragmentHeader()
    {
        $minSize = 2;
        $minRemain = $minSize - strlen($this->unparsedFragment);

        if ($this->willBlock($minRemain)) {
            return null;
        }

        $this->unparsedFragment .= $this->read($minRemain);

        $payloadLength = ord($this->unparsedFragment[1]) & 127; // Bits 1-7 in byte 1

        switch ($payloadLength) {
            default:
                return $this->unparsedFragment;
            case self::PAYLOAD_LENGTH_16BIT:
                $extraHeaderBytes = 2;
                break;
            case self::PAYLOAD_LENGTH_64BIT:
                $extraHeaderBytes = 8;
                break;
        }

        $extraRemain = $minSize + $extraHeaderBytes - strlen($this->unparsedFragment);

        if ($this->willBlock($extraRemain)) {
            return null;
        }

        $this->unparsedFragment .= $this->read($extraRemain);

        return $this->unparsedFragment;
    }

    /**
     * @return bool|string|null
     *
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     * @throws \Overtrue\WebSocket\Exceptions\InvalidOpcodeException
     */
    protected function receiveFragment()
    {
        $data = $this->receiveFragmentHeader();

        // Buffer not ready for header
        if (null === $data) {
            return null;
        }

        // Is this the final fragment?  // Bit 0 in byte 0
        /// @todo Handle huge payloads with multiple fragments.
        $final = ord($data[0]) & self::FINAL_BIT;

        // Should be zero
        $rsv = ord($data[0]) & ~self::FIRST_BYTE_MASK;

        if (0 !== $rsv) {
            throw new ConnectionException('Reserved bits should be zero');
        }

        // Parse opcode
        $opcodeId = ord($data[0]) & self::OPCODE_MASK;
        $opcodes = array_flip(self::OPCODES);

        if (!array_key_exists($opcodeId, $opcodes)) {
            throw new ConnectionException("Bad opcode in websocket frame: $opcodeId");
        }

        $opcode = $opcodes[$opcodeId];

        // record the opcode if we are not receiving a continuation fragment
        if ('continuation' !== $opcode) {
            $this->lastOpcode = $opcode;
        }

        // Masking?
        $mask = ord($data[1]) & self::MASKED_BIT;

        $payload = '';

        // Payload length
        $payloadLength = ord($data[1]) & self::PAYLOAD_LENGTH_MASK;

        if ($payloadLength > 125) {
            // 126: 'n' means big-endian 16-bit unsigned int
            // 127: 'J' means big-endian 64-bit unsigned int
            $payloadLength = current(unpack(self::PAYLOAD_LENGTH_16BIT === $payloadLength ? 'n' : 'J', substr($data, 2)));
        }

        // Try again later when fragment is downloaded
        if ($this->willBlock($mask * 4 + $payloadLength)) {
            return null;
        }

        // Enter fragment reading state
        $this->unparsedFragment = '';

        // Get masking key.
        if ($mask) {
            $maskingKey = $this->read(4);
        }

        // Get the actual payload, if any (might not be for e.g. close frames.
        if ($payloadLength > 0) {
            $data = $this->read($payloadLength);

            if ($mask) {
                // Unmask payload.
                for ($i = 0; $i < $payloadLength; ++$i) {
                    $data[$i] = $data[$i] ^ $maskingKey[$i & 3];
                }
            }

            $payload = $data;
        }

        if ('close' === $opcode) {
            // Get the close status.
            if ($payloadLength >= 2) {
                $status = current(unpack('n', $payload)); // read 16-bit short

                $this->closeStatus = $status;
                $payload = substr($payload, 2);
                $statusBin = $payload[0].$payload[1];

                if (!$this->closing) {
                    $this->send($statusBin.'Close acknowledged: '.$status, 'close', true); // Respond.
                }
            }

            if ($this->closing) {
                $this->closing = false; // A close response, all done.
            }

            // And close the socket.
            fclose($this->socket);
            $this->connected = false;
        }

        // if this is not the last fragment, then we need to save the payload
        if (!$final) {
            $this->hugePayload .= $payload;

            return null;
        } elseif ($this->hugePayload) { // this is the last fragment, and we are processing a hugePayload
            // sp we need to retrieve the whole payload
            $payload = $this->hugePayload .= $payload;
            $this->hugePayload = '';
        }

        return $payload;
    }

    /**
     * Tell the socket to close.
     *
     * @param int    $status  http://tools.ietf.org/html/rfc6455#section-7.4
     * @param string $message a closing message, max 125 bytes
     *
     * @return bool|string|null
     *
     * @throws \Overtrue\WebSocket\Exceptions\InvalidOpcodeException
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     */
    public function close($status = 1000, $message = 'ttfn')
    {
        $this->send(pack('n', $status).$message, 'close', true);

        $this->closing = true;

        return $this->receive(); // Receiving a close frame will close the socket now.
    }

    /**
     * @param array|string $data
     *
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     */
    protected function write($data)
    {
        // Array contains binary data and split-ed bytes
        if (is_array($data)) {
            foreach ($data as $part) {
                $this->write($part);
            }

            return;
        }

        // If it is not binary data, then it is byte
        if (!is_string($data)) {
            $data = pack('C', $data);
        }

        $written = fwrite($this->socket, $data);

        if ($written < strlen($data)) {
            throw new ConnectionException(
                "Could only write $written out of ".strlen($data).' bytes.'
            );
        }
    }

    /**
     * @param int $length
     *
     * @return bool|string
     *
     * @throws \Overtrue\WebSocket\Exceptions\ConnectionException
     */
    protected function read(int $length)
    {
        $data = &$this->socketBuffer;

        while (strlen($data) < $length) {
            $buffer = fread($this->socket, $length - strlen($data));

            if (false === $buffer) {
                $metadata = stream_get_meta_data($this->socket);
                throw new ConnectionException(
                    'Broken frame, read '.strlen($data).' of stated '
                    .$length.' bytes.  Stream state: '
                    .json_encode($metadata)
                );
            }

            if ('' === $buffer) {
                $metadata = stream_get_meta_data($this->socket);
                throw new ConnectionException(
                    'Empty read; connection dead?  Stream state: '.json_encode($metadata)
                );
            }
            $data .= $buffer;
        }

        $return = substr($data, 0, $length);
        $data = substr($data, $length);

        return $return;
    }

    /**
     * @param int $length
     *
     * @return bool
     */
    protected function bufferize(int $length)
    {
        while (1) {
            $bufferLength = strlen($this->socketBuffer);
            $remain = $length - $bufferLength;

            if ($remain <= 0) {
                return true;
            }

            $fetched = fread($this->socket, $remain);

            if (false === $fetched) {
                break;
            }

            if (0 == strlen($fetched)) {
                break;
            }

            $this->socketBuffer .= $fetched;
        }

        return false;
    }

    /**
     * @param int $length
     *
     * @return bool
     */
    protected function willBlock(int $length)
    {
        return !$this->bufferize($length);
    }
}
