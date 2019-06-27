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

    /**
     * @var resource
     */
    protected $socket;

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

        $this->socket = fsockopen($options['hostname'], $options['port']);
        // make sure the stream is not blocking, so we don't have to wait for data
        stream_set_blocking($this->socket, false);

        $this->connect();

        while (true) {
            $this->read();
        }
    }

    /**
     * Read.
     */
    public function read()
    {
        // try to read 1 byte from the socket
        $type = stream_get_contents($this->socket, 1);

        if (strlen($type) == 0) {
            // no data yet
            return NULL;
        }

        $bytesRead = 1;

        $type = unpack('c', $type)[1];

        // got a packet, read length
        $multiplier = 1;
        $len = 0;
        do {
            $encodedByte = unpack('c', stream_get_contents($this->socket, 1))[1];
            $bytesRead++;
            $len += ($encodedByte & 0x7F) * $multiplier;
            $multiplier *= 0x80;
            if ($multiplier > 0x80*0x80*0x80) {
                throw new \RuntimeException("Malformed length");
            }
        } while (($encodedByte & 0x80) != 0);

        if ($len > $bytesRead) {
            echo "Still need to read some.\n";
        }

        if ($type === self::TYPE_CONNACK) {
            echo "CONNACK\n";
        }
    }

    /**
     * Connect.
     */
    protected function connect()
    {
        $protocol = 'MQTT';
        $ident = "TestIdent";

        // variable headers

        // protocol length is encoded here
        $headers = pack("n", strlen($protocol)) . $protocol;
        // protocol level = 4
        $headers .= pack('c', 0x04);
        // connect flags (TODO: other than just clean session)
        $headers .= pack('c', 0x02);
        // keepalive (short, 10)
        $headers .= pack('c2', 0x00, 0x0A);

        // payload

        // identifier
        $payload = pack("n", strlen($ident)) . $ident;

        $this->send(self::TYPE_CONNECT, $headers, $payload);
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
