<?php

use App\Enums\LoanScheduleType;
use App\Support\LoanCalculator;

test('annuity schedule has an equal payment and repays the principal exactly', function () {
    $rows = LoanCalculator::schedule(1_000_000, 12, 12);

    expect($rows[0])->toBe(['number' => 1, 'payment' => 88849, 'principal' => 78849, 'interest' => 10000, 'balance' => 921151])
        ->and(array_unique(array_column(array_slice($rows, 0, 11), 'payment')))->toBe([88849])
        ->and(array_sum(array_column($rows, 'principal')))->toBe(1_000_000)
        ->and(end($rows)['balance'])->toBe(0);
});

test('differential schedule pays equal principal with decreasing interest', function () {
    $rows = LoanCalculator::schedule(120_000, 24, 3, LoanScheduleType::Differential);

    expect(array_column($rows, 'principal'))->toBe([40000, 40000, 40000])
        ->and(array_column($rows, 'interest'))->toBe([2400, 1600, 800])
        ->and(array_column($rows, 'payment'))->toBe([42400, 41600, 40800]);
});

test('a zero rate loan is split without interest', function () {
    $summary = LoanCalculator::summary(100_000, 0, 3);

    expect($summary['interest'])->toBe(0)
        ->and($summary['total'])->toBe(100_000)
        ->and(array_sum(array_column(LoanCalculator::schedule(100_000, 0, 3), 'principal')))->toBe(100_000);
});
