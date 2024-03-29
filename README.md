A client implementation for the MQTT protocol version 3.1.1.

# Installation

To install, use `composer`:

``` sh
composer require kokx/mqtt
```

# Usage

To use php-mqtt, create an instance of the `MQTT\Client` class with a hostname
and call `connect()` to connect to the the broker. Now you can `subscribe()` to
topics and `publish()` messages to the broker.

Note that the client is non-blocking. You could publish messages with QoS 0 and
then immediately break the connection, but any scenario where you would need
blocking behavior will not work.

To ensure that messages are received and that the connection keeps open, you
need to repeatedly call `loop()`. To ensure that the connection stays open
without sudden reconnects, the keepalive time (32 seconds by default) should be
at least 3 times the interval between calls to `loop()`. But it is recommended
to call `loop()` more often for swift passing of messages on subscribed topics.

For more complete usage instructions, look at the documentation in [docs/usage.md](docs/usage.md).

## Example

``` php
<?php

use MQTT\Client as MQTTClient;

$client = new MQTTClient([
    'hostname' => 'localhost'
]);

$client->connect();

$client->subscribe('state/device/#', function ($topic, $payload) {
    echo "Received message on topic $topic with payload $payload";
});

// repetetively call loop() to keep receiving messages
while (true) {
    $client->loop();
    usleep(50000);
}
```

# TODO

- [X] Refactor retransmission
- [X] Read all available messages in every execution of `loop()`
- [X] Remove debugging `echo` statements (maybe implement logging)
- [X] Upload to packagist
- [X] Installation instructions
- [ ] TLS Support
- [ ] Protocol version 5 support
