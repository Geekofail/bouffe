<?php

use App\Support\TimeLimit;

test('une limite de temps n\'est jamais imposée là où il n\'y en a pas', function () {
    $before = ini_get('max_execution_time');

    ini_set('max_execution_time', '0');
    TimeLimit::atLeast(90);
    expect(ini_get('max_execution_time'))->toBe('0');

    ini_set('max_execution_time', '30');
    TimeLimit::atLeast(90);
    expect(ini_get('max_execution_time'))->toBe('90');

    TimeLimit::atLeast(60);   // jamais raccourcie
    expect(ini_get('max_execution_time'))->toBe('90');

    set_time_limit((int) $before);
});
