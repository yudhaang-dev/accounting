<?php

use App\Support\Money\DecimalCast;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;

it('keeps a fixed scale and exposes a decimal string', function () {
    $money = Money::of('1500', 'IDR', 2);

    expect($money->toString())->toBe('1500.00')
        ->and($money->scale())->toBe(2);
});

it('adds and subtracts without floating point error', function () {
    $sum = Money::of('0.10', 'USD', 2)->plus(Money::of('0.20', 'USD', 2));

    expect($sum->toString())->toBe('0.30')
        ->and($sum->minus(Money::of('0.30', 'USD', 2))->isZero())->toBeTrue();
});

it('refuses to lose precision silently', function () {
    Money::of('1.005', 'USD', 2);
})->throws(RoundingNecessaryException::class);

it('rounds only when a rounding mode is given', function () {
    expect(Money::of('1.005', 'USD', 2, RoundingMode::HalfUp)->toString())->toBe('1.01')
        ->and(Money::of('100.00', 'USD', 2)->multipliedBy('15432.123456', RoundingMode::HalfUp)->toString())->toBe('1543212.35');
});

it('rejects arithmetic across currencies', function () {
    Money::of('1', 'USD', 2)->plus(Money::of('1', 'IDR', 2));
})->throws(InvalidArgumentException::class);

it('compares by value and currency', function () {
    expect(Money::of('1', 'USD', 2)->equals(Money::of('1.00', 'USD', 2)))->toBeTrue()
        ->and(Money::of('1', 'USD', 2)->equals(Money::of('1', 'IDR', 2)))->toBeFalse()
        ->and(Money::of('-1', 'USD', 2)->isNegative())->toBeTrue();
});

it('casts decimals at a fixed scale in both directions', function () {
    $cast = new DecimalCast(2);
    $model = new class extends Model {};

    expect($cast->get($model, 'amount', '10.5', [])->toString())->toBe('10.50')
        ->and($cast->set($model, 'amount', BigDecimal::of('7'), []))->toBe('7.00')
        ->and($cast->set($model, 'amount', null, []))->toBeNull()
        ->and(fn () => $cast->set($model, 'amount', '1.001', []))->toThrow(RoundingNecessaryException::class);
});
