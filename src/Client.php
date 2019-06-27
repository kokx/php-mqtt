<?php

namespace MQTT;

/**
 * MQTT Client
 */
class Client
{

    const DEFAULT_PORT = 1883;

    const TYPE_CONNECT = 0x10;
    const TYPE_CONNACK = 0x20;
    const TYPE_PINGREQ = 0xC0;
    const TYPE_PINGRESP = 0xD0;

    /**
     * @var resource
     */
    protected $socket;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var int
     */
    protected $keepalive = 32;

    /**
     * Last time a control message was sent
     * @var int
     */
    protected $lastControlMessage;

    /**
     * Constructor.
     *
     * @param array $options
     */
    public function __construct(array $options)
    {
        if (!isset($options['port'])) {
            $options['port'] = self::DEFAULT_PORT;
        }
        if (isset($options['keepalive'])) {
            $keepalive = (int) $options['keepalive'];
            // ensure keepalive is at least 0 and at most 0xFFFF
            $keepalive = max(0, $keepalive);
            $this->keepalive = min(0xFFFF, $keepalive);
        }

        $this->options = $options;
    }

    /**
     * Loop.
     */
    public function loop()
    {
        if (time() > $this->lastControlMessage + $this->keepalive) {
            // send a ping request
            $this->pingreq();
        }
        $this->read();
    }

    /**
     * Read.
     */
    public function read()
    {
        if (feof($this->socket)) {
            echo "Stream ended. Reconnecting...";
            $this->connect();
        }
        // try to read 1 byte from the socket
        $type = stream_get_contents($this->socket, 1);

        if (strlen($type) == 0) {
            // no data yet
            return NULL;
        }

        $type = unpack('C', $type)[1];

        // got a packet, read length
        $multiplier = 1;
        $len = 0;
        do {
            $encodedByte = unpack('C', stream_get_contents($this->socket, 1))[1];
            $len += ($encodedByte & 0x7F) * $multiplier;
            $multiplier *= 0x80;
            if ($multiplier > 0x80*0x80*0x80) {
                throw new \RuntimeException("Malformed length");
            }
        } while (($encodedByte & 0x80) != 0);

        $data = stream_get_contents($this->socket, $len);

        switch ($type) {
            case self::TYPE_CONNACK:
                echo "CONNACK\n";
                break;
            case self::TYPE_PINGREQ:
                echo "PINGREQ\n";
                break;
            case self::TYPE_PINGRESP:
                echo "PINGRESP\n";
                break;
            default:
                echo "Don't know: " . $type . "\n";
                break;
        }
    }

    /**
     * Send a ping request.
     */
    protected function pingreq()
    {
        $this->send(self::TYPE_PINGREQ, "", "");
        $this->lastControlMessage = time();
    }

    /**
     * Connect.
     */
    public function connect()
    {
        $this->socket = fsockopen($this->options['hostname'], $this->options['port']);
        // make sure the stream is not blocking, so we don't have to wait for data
        stream_set_blocking($this->socket, false);

        $protocol = 'MQTT';
        $ident = "TestIdent";

        // variable headers

        // protocol name length + protocol
        $headers = pack("n", strlen($protocol)) . $protocol;
        // protocol level = 4
        $headers .= pack('c', 0x04);
        // connect flags (TODO: other than just clean session)
        $headers .= pack('c', 0x02);
        // keepalive
        $headers .= pack('n', $this->keepalive);

        // payload

        // identifier
        $payload = pack("n", strlen($ident)) . $ident;

        $this->send(self::TYPE_CONNECT, $headers, $payload);
        $this->lastControlMessage = time();
    }

    /**
     * Send a message
     *
     * @param int $type
     * @param string $headers
     * @param string $payload
     */
    protected function send($type, $headers, $payload)
    {
        // for now, we assume that all parts of the variable header are one-byte long
        $len = strlen($headers) + strlen($payload);

        if ($len > 127) {
            throw new \RuntimeException("Length above 127 not implemented yet.");
        }

        // message type
        $msg = pack('cc', $type, $len) . $headers . $payload;

        fwrite($this->socket, $msg);
    }
}
