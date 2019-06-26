<h1 align="center"> WebSocket </h1>

<p align="center"> A PHP implementation of WebSocket.</p>


## Installing

```shell
$ composer require overtrue/websocket -vvv
```

## Usage

### Server

```php
use Overtrue\WebSocket\Server;

$options = [
    // 'port' => 8000,
    // 'timeout' => 0,
    // ...
];
$server = new Server($options);

$server->accept();

// receive
$message = $server->reveive();

// send
$server->send('Hello overtrue.');
```

### Client

```php
use Overtrue\WebSocket\Client;

$client = new Client('ws://127.0.0.1:8000');

// send
$client->send('Hello overtrue.');

// receive
$message = $client->reveive();
```

## Contributing

You can contribute in one of three ways:

1. File bug reports using the [issue tracker](https://github.com/overtrue/websocket/issues).
2. Answer questions or fix bugs on the [issue tracker](https://github.com/overtrue/websocket/issues).
3. Contribute new features or update the wiki.

_The code contribution process is not very formal. You just need to make sure that you follow the PSR-0, PSR-1, and PSR-2 coding guidelines. Any new code contributions must be accompanied by unit tests where applicable._

## License

MIT