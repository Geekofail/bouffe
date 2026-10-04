<?php

use App\Support\NetworkAddresses;

it('reconnaît les adresses du réseau local', function (string $ip, bool $lan) {
    expect((new NetworkAddresses)->isLan($ip))->toBe($lan);
})->with([
    ['192.168.1.20', true],
    ['10.0.0.5', true],
    ['172.20.10.2', true],
    ['127.0.0.1', false],
    ['169.254.3.4', false],
    ['8.8.8.8', false],
    ['::1', false],
    ['pas une ip', false],
]);
