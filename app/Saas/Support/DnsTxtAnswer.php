<?php

namespace App\Saas\Support;

final class DnsTxtAnswer
{
    /** @param array<int, string> $answers */
    public static function contains(array $answers, string $expected): bool
    {
        foreach ($answers as $answer) {
            if (hash_equals($expected, trim($answer, " \t\n\r\0\x0B\""))) {
                return true;
            }
        }

        return false;
    }
}
