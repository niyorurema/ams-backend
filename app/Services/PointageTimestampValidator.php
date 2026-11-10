<?php

namespace App\Services;

use DateTimeImmutable;

final class PointageTimestampValidator
{
    public const MAX_FUTURE_SECONDS = 300;

    public static function isValid(
        string $value,
        ?DateTimeImmutable $now = null,
        int $maxFutureSeconds = self::MAX_FUTURE_SECONDS
    ): bool
    {
        self::validerMarge($maxFutureSeconds);
        $date = self::parse($value);
        if ($date === null || (int) $date->format('Y') < 2001) {
            return false;
        }

        $now ??= new DateTimeImmutable();

        return $date <= $now->modify('+' . $maxFutureSeconds . ' seconds');
    }

    public static function isTooFarInFuture(
        string $value,
        ?DateTimeImmutable $now = null,
        int $maxFutureSeconds = self::MAX_FUTURE_SECONDS
    ): bool
    {
        self::validerMarge($maxFutureSeconds);
        $date = self::parse($value);
        if ($date === null) {
            return false;
        }

        $now ??= new DateTimeImmutable();

        return $date > $now->modify('+' . $maxFutureSeconds . ' seconds');
    }

    private static function validerMarge(int $maxFutureSeconds): void
    {
        if ($maxFutureSeconds < 0) {
            throw new \InvalidArgumentException('La marge de date future ne peut pas être négative.');
        }
    }

    private static function parse(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || $date->format('Y-m-d H:i:s') !== $value
            || $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
        ) {
            return null;
        }

        return $date;
    }
}
