<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class Phone
{
    public static function normalize(string $input): string
    {
        $input = strtr($input, array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('0123456789')));
        $input = strtr($input, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('0123456789')));
        $value = preg_replace('/[\s()\-]/u', '', $input);
        $value = preg_replace('/^(?:\+964|00964|964|0)/', '', $value);
        if (! preg_match('/^7\d{9}$/', $value)) {
            throw ValidationException::withMessages(['phone' => 'اكتب رقم هاتف عراقي صحيحاً يبدأ بـ07 أو ‎+9647.']);
        }

        return '+964'.$value;
    }
}
