<?php

namespace MQTT;

/**
 * MQTT Client
 */
class Client
{

    const DEFAULT_PORT = 1883;
    const RETRANSMIT_TIME = 32;

    const TYPE_CONNECT = 0x10;
    const TYPE_CONNACK = 0x20;
    const TYPE_PUBLISH = 0x30;
    const TYPE_PUBACK = 0x40;
    const TYPE_PUBREC = 0x50;
    const TYPE_PUBREL = 0x60;
    const TYPE_PUBCOMP = 0x70;
    const TYPE_SUBSCRIBE = 0x80;
    const TYPE_SUBACK = 0x90;
    const TYPE_UNSUBSCRIBE = 0xA0;
    const TYPE_UNSUBACK = 0xB0;
    const TYPE_PINGREQ = 0xC0;
    const TYPE_PINGRESP = 0xD0;
    const TYPE_DISCONNECT = 0xE0;

    const FLAG_SUBSCRIBE = 0x02;
    const FLAG_PUBREL = 0x02;

    const PUB_STATE_PUBLISHED = 0;
    const PUB_STATE_PUBREL = 1;

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
     * Topic subscriptions.
     * @var array
     */
    protected $subscriptions = [];

    /**
     * Current state of the protocol. Wiped on reconnect.
     *
     * Contains information about stateful parts of the protocol. Such as the
     * state information around QoS.
     *
     * Every element of this array contains:
     * - last_change, the time() of the last moment it was changed
     * - onfail, a callback that will be called if the timeout expired,
     *   used to retry sending most of the time
     * - data, all context of the state
     *
     * The key will always be the identifier of the message chain
     *
     * @var array
     */
    protected $state = [];

    /**
     * Constructor.
     *
     * @param array $options
     */
    public function __construct(array $options)
    {
        if (!isset($options['hostname'])) {
            throw new \InvalidArgumentException("No hostname for connection given.");
        }
        if (!isset($options['port'])) {
            $options['port'] = self::DEFAULT_PORT;
        }
        if (!isset($options['ident'])) {
            $options['ident'] = 'php-mqtt';
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
     * Read an incoming message and send a ping if needed.
     *
     * This function will also check if messages need to be retransmitted.
     *
     * To prevent accidental disconnects, run this function at least every 1/3*keepalive seconds.
     */
    public function loop()
    {
        // read until there are no more messages waiting
        while ($this->read());

        foreach ($this->state as $identifier => $info) {
            if ($info['last_change'] + self::RETRANSMIT_TIME < time()) {
                $info['onfail']();
                $this->state[$identifier]['last_change'] = time();
            }
        }

        if (time() > $this->lastControlMessage + $this->keepalive) {
            // send a ping request
            $this->pingreq();
        }
    }

    /**
     * Read.
     *
     * Returns true when it has read data, false if not.
     */
    protected function read() : bool
    {
        if (feof($this->socket)) {
            $this->connect();
        }
        // try to read 1 byte from the socket
        $type = stream_get_contents($this->socket, 1);

        if (strlen($type) == 0) {
            // no data yet
            return false;
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

        // split the type and flags
        $flags = $type & 0x0F;
        $type = $type & 0xF0;

        switch ($type) {
            case self::TYPE_CONNACK:
            case self::TYPE_PINGREQ:
            case self::TYPE_PINGRESP:
                // nothing to do here
                break;
            case self::TYPE_SUBACK:
                $ident = unpack('nident', $data)['ident'];
                unset($this->state[$ident]);
                break;
            case self::TYPE_PUBLISH:
                $this->recvPublish($flags, $data);
                break;
            case self::TYPE_PUBREL:
                $this->recvPubrel($flags, $data);
                break;
            case self::TYPE_PUBACK:
                // TODO verify qos type
                $ident = unpack('n', $data)[1];
                unset($this->state[$ident]);
            case self::TYPE_PUBREC:
                // send PUBREL
                // TODO verify qos type
                $ident = unpack('n', $data)[1];
                $headers = pack('n', $ident);
                $this->send(self::TYPE_PUBREL | self::FLAG_PUBREL, $headers, '');
                $this->state[$ident] = [
                    'last_change' => time(),
                    'onfail' => function() use ($headers) {
                        $this->send(self::TYPE_PUBREL | self::FLAG_PUBREL, $headers, '');
                    }
                ];
                break;
            case self::TYPE_PUBCOMP:
                // finish publish qos 2
                // TODO verify qos type
                $ident = unpack('n', $data)[1];
                unset($this->state[$ident]);
                break;
            default:
                // unknown type, just ignore for now
                break;
        }

        return true;
    }

    /**
     * Receive a pubrel message.
     * @param int $flags
     * @param string $data
     */
    public function recvPubrel(int $flags, string $data)
    {
        if ($flags !== 0x02) {
            // TODO: maybe handle this more gracefully
            throw new \RuntimeException("The broker sent an invalid PUBREL.");
        }

        $identifier = unpack('n', $data)[1];

        $this->send(self::TYPE_PUBCOMP, pack('n', $identifier), '');
        // release the identifier locally
        unset($this->state[$identifier]);
    }

    /**
     * Receive a publish message.
     * @param int $flags
     * @param string $data
     */
    public function recvPublish(int $flags, string $data)
    {
        $qos = ($flags & 0x06) >> 1;
        $topiclen = unpack('n', $data)[1];
        $topic = substr($data, 2, $topiclen);
        $bytesread = $topiclen + 2;

        // there only is an identifier when qos != 0
        if ($qos != 0) {
            $identifier = unpack('n', $data, $bytesread)[1];
            $bytesread += 2;
        }

        if ($qos == 1) {
            $this->send(self::TYPE_PUBACK, pack('n', $identifier), '');
        }
        if ($qos == 2) {
            $this->send(self::TYPE_PUBREC, pack('n', $identifier), '');
            if (isset($this->state[$identifier])) {
                // second receive, make sure we do not execute twice
                return;
            }
            $this->state[$identifier] = [
                'last_change' => time(),
                'onfail' => function() use ($identifier) {
                    // resend pubrec
                    $this->send(self::TYPE_PUBREC, pack('n', $identifier), '');
                },
                'data' => [
                    'qos' => $qos
                ]
            ];
        }

        $payload = substr($data, $bytesread);

        // trigger callback for this message
        foreach ($this->subscriptions as $subscription) {
            if ($this->topicMatches($topic, $subscription['topic'])) {
                $subscription['callback']($topic, $payload);
            }
        }
    }

    /**
     * Check if a topic matches a topic spec
     * @param string $topic
     * @param string $spec
     */
    protected function topicMatches(string $topic, string $spec)
    {
        $spec = str_replace('+', '[^/]+', $spec);
        $spec = str_replace('#', '.*', $spec);
        $spec = str_replace('/', '\\/', $spec);
        $spec = '/^' . $spec . '$/';

        return preg_match($spec, $topic);
    }

    /**
     * Subscribe to a topic.
     * @param string $topic
     * @param callable $callback To be called when a message for the topic comes in.
     * @param int $qos Maximum QoS to be sent
     */
    public function subscribe(string $topic, callable $callback, int $qos = 0)
    {
        if ($qos < 0 || $qos > 2) {
            throw new \InvalidArgumentException("Invalid QoS given, must be between 0 and 2 (inclusive).");
        }
        $identifier = mt_rand(0, 0xFFFF);
        // TODO: store the identifier somehow

        $header = pack('n', $identifier);
        $payload = pack('n', strlen($topic)) . $topic . pack('c', $qos);

        $this->state[$identifier] = [
            'last_change' => time(),
            'onfail' => function() use ($header, $payload) {
                $this->send(self::TYPE_SUBSCRIBE | self::FLAG_SUBSCRIBE, $header, $payload);
            }
        ];
        $this->send(self::TYPE_SUBSCRIBE | self::FLAG_SUBSCRIBE, $header, $payload);
        $this->subscriptions[$identifier] = [
            'topic'    => $topic,
            'callback' => $callback
        ];
    }

    /**
     * Publish a message.
     * @param string $topic
     * @param string $payload
     * @param int $qos QoS for the message
     * @param boolean $retain Should the message be retained
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false)
    {
        if ($qos < 0 || $qos > 2) {
            throw new \InvalidArgumentException("Incorrect QoS argument given, must be 0, 1 or 2.");
        }
        $retain = (int) $retain;
        $flags = ($qos << 1) | $retain;

        $headers = pack('n', strlen($topic)) . $topic;

        if ($qos > 0) {
            $identifier = mt_rand(0, 0xFFFF);
            $headers .= pack('n', $identifier);

            $this->state[$identifier] = [
                'last_change' => time(),
                'onfail' => function() use ($flags, $headers, $payload) {
                    $this->send(self::TYPE_PUBLISH | $flags, $headers, $payload);
                }
            ];
        }

        $this->send(self::TYPE_PUBLISH | $flags, $headers, $payload);
    }

    /**
     * Send a ping request.
     */
    protected function pingreq()
    {
        $this->send(self::TYPE_PINGREQ, '', '');
    }

    /**
     * Connect.
     */
    public function connect()
    {
        $this->socket = fsockopen($this->options['hostname'], $this->options['port']);
        if ($this->socket === false) {
            throw new \RuntimeException("Could not connect to the broker.");
        }
        // make sure the stream is not blocking
        stream_set_blocking($this->socket, false);

        $protocol = 'MQTT';
        $ident = $this->options['ident'];

        // find out which connect flags need to be set
        $flags = 0;
        if (isset($this->options['clean_session']) && $this->options['clean_session']) {
            $flags |= 0x02;
        }
        // TODO: implement will
        if (isset($this->options['username'])) {
            $flags |= 0x80;
        }
        if (isset($this->options['password'])) {
            $flags |= 0x40;
        }

        // variable headers

        // protocol name length + protocol
        $headers = pack("n", strlen($protocol)) . $protocol;
        // protocol level = 4 (version 3.1.1)
        $headers .= pack('c', 0x04);
        // connect flags (TODO: other than just clean session)
        $headers .= pack('c', $flags);
        // keepalive
        $headers .= pack('n', $this->keepalive);

        // payload

        // identifier
        $payload = pack("n", strlen($ident)) . $ident;

        // TODO: if will = 1, include will topic and contents

        if (isset($this->options['username'])) {
            $payload .= pack('n', strlen($this->options['username'])) . $this->options['username'];
        }
        if (isset($this->options['password'])) {
            $payload .= pack('n', strlen($this->options['password'])) . $this->options['password'];
        }

        // reset the state
        $this->state = [];

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
        $this->lastControlMessage = time();
    }
}
