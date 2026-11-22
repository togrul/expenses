<?php

use App\Support\Money;

test('parses human amounts into minor units', function (string $input, int $expected) {
    expect(Money::parse($input))->toBe($expected);
})->with([
    ['1 234,50', 123450],
    ['12.5', 1250],
    ['0,01', 1],
    ['1234', 123400],
    ['1.234,56', 123456],
    ['1,234.56', 123456],
    ["1\u{00A0}000", 100000],
    ['150 ₼', 15000],
]);

test('rejects negative, empty and malformed amounts', function (string $input) {
    Money::parse($input);
})->with(['', '   ', '-5', 'abc', '1,234', '12,345.6.7', '1.999'])->throws(InvalidArgumentException::class);

test('formats minor units with thousands separator and currency symbol', function () {
    expect(Money::format(123450, 'AZN'))->toBe('1 234,50 ₼')
        ->and(Money::format(5, 'AZN'))->toBe('0,05 ₼')
        ->and(Money::format(-2500, 'AZN'))->toBe('−25,00 ₼')
        ->and(Money::toInput(123450))->toBe('1234,50');
});

test('splits an amount evenly and puts the remainder on the last part', function () {
    expect(Money::split(100000, 3))->toBe([33333, 33333, 33334])
        ->and(array_sum(Money::split(100001, 7)))->toBe(100001);
});
