<?php

namespace App\Services;

use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class CostingMath
{
    public static function scaled(string|int $value, int $places = 3): int
    {
        $text = (string) $value;
        if (! preg_match('/^\d{1,10}(?:\.\d{1,'.$places.'})?$/D', $text)) {
            throw ValidationException::withMessages(['quantity' => 'استخدم رقماً موجباً وبحد أقصى '.$places.' منازل عشرية.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        return (int) $whole * (10 ** $places) + (int) str_pad($fraction, $places, '0');
    }

    public static function mulDiv(int $value, int $multiplier, int $divisor, RoundingMode $rounding = RoundingMode::HalfUp): int
    {
        if ($divisor <= 0) {
            throw ValidationException::withMessages(['cost' => 'سجّل شراء المادة وكلفتها قبل حساب الإنتاج.']);
        }
        try {
            $result = BigInteger::of($value)->multipliedBy($multiplier)->dividedBy($divisor, $rounding)->toInt();
        } catch (MathException $exception) {
            throw ValidationException::withMessages(['cost' => 'الأرقام تتجاوز مجال الحساب المتاح. راجع الكمية والكلفة.']);
        }
        if (abs($result) > 9000000000000000) {
            throw ValidationException::withMessages(['cost' => 'الأرقام تتجاوز مجال الحساب المتاح. راجع الكمية والكلفة.']);
        }

        return $result;
    }

    public static function format(int $value, bool $group = true): string
    {
        $absolute = abs($value);
        $whole = intdiv($absolute, 1000);
        $fraction = rtrim(str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT), '0');

        return ($value < 0 ? '-' : '').($group ? number_format($whole) : (string) $whole).($fraction === '' ? '' : '.'.$fraction);
    }
}
