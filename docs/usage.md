# Connecting

To connect to an MQTT broker, create an `MQTT\Client` instance, and call
`connect`. The following connection options are available:

| Option          | Default    | Remarks                                                                               |
|-----------------|------------|---------------------------------------------------------------------------------------|
| `hostname`      |            | Required                                                                              |
| `port`          | 1883       |                                                                                       |
| `ident`         | `php-mqtt` | Should be unique for the broker                                                       |
| `keepalive`     | 32         | The `loop()` function should be called at least 3 times within one keepalive interval |
| `username`      |            | Username to be sent to the broker                                                     |
| `password`      |            | Password to be sent to the broker                                                     |
| `clean_session` | false      | If true, the clean session flag will be passed to the broker.                         |


## Keeping the connection alive

MQTT is a protocol which runs over a longer amount of time. The broker can
publish messages to a client at any moment. These messages should be relayed to
the correct functions as soon as they arrive. Thus, the `MQTT\Client` class has
a `loop()` function which should be called often.

It is recommended to call the `loop()` function many times per second, to check
for new messages from the broker. This ensures that the connection with the
broker is kept online without issues. If needed, the `keepalive` interval can be
set higher, to increase the interval of pings between the client and the broker.
Note that it is recommended to at least call `loop()` three times within one
`keepalive` interval, to ensure a good connection.

The `loop()` function is non-blocking, thus it will only check if there are new
messages. It will not wait until new messages arrive.

## Example

```php
<?php
use MQTT\Client as MQTTClient;

$client = new MQTTClient([
    'hostname' => 'localhost'
]);

$client->connect();

while (true) {
    $client->loop();
    usleep(50000);
}
```

# Subscribing to topics

To subscribe to a topic, use the `subscribe` method. Which takes a topic
specifier and a callback. Optionally the maximum QoS to be sent to the
for this topic can be specified (which is 0 by default).

``` php
$client->subscribe('php-mqtt/#', function ($topic, $payload) {
    // gets executed when a message that starts with `php-mqtt/` is published to
    // the broker
});
```

## Topic specifier

A topic specifier for the `subscribe` method can contain a `+` anywhere and a
`#` at the end of the topic. These are wildcards. The `+` matches any character
except for a `/`, and the `#` matches any character. If you want to receive all
messages from the broker, you can subscribe to the `#` topic.

# Publishing messages

To publish a message to the broker, use the `publish` method. This takes a
topic, payload and optionally a QoS and retain flag.

## QoS

The QoS (Quality of Service) determines much effort is put into making sure a
published message arrives. There are three QoS levels:

| QoS | About                                                                                             |
|-----|---------------------------------------------------------------------------------------------------|
| 0   | Fire and forget, the message is published, arrival is not verified                                |
| 1   | At least once, the message is published and retransmitted if the other party does not acknowledge |
| 2   | Exactly once, the message is published first and released later                                   |

Of these QoS levels, QoS 2 is the most data-heavy, and requires multiple
messages being sent back and forth between the client and broker but it gives
the strongest guarantees. By default, php-mqtt uses a QoS of 0 for all messages.

## Retain

The retain flag specifies if the last message in the topic should be retained by
the broker and retransmitted to clients when they reconnect. Specifying the
retain flag in a published message, will ensure that it is sent to a client
subscribing to the topic.

## Example

``` php
$client->publish('php-mqtt/publish', 'Hello, World!');

// with QoS = 2 and retain flag set
$client->publish('php-mqtt/publish/retain', 'Hello, World!', 2, true);
```
