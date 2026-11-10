<?php

namespace Tests\Unit;

use App\Services\PointageTimestampValidator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PointageTimestampValidatorTest extends TestCase
{
    public function testAcceptsCurrentAndSlightlyFutureDates(): void
    {
        $now = new DateTimeImmutable('2026-11-10 08:00:00');

        self::assertTrue(PointageTimestampValidator::isValid('2026-11-10 08:00:00', $now));
        self::assertTrue(PointageTimestampValidator::isValid('2026-11-10 08:05:00', $now));
    }

    public function testRejectsDatesMoreThanFiveMinutesInTheFuture(): void
    {
        $now = new DateTimeImmutable('2026-11-10 08:00:00');

        self::assertFalse(PointageTimestampValidator::isValid('2026-11-10 08:05:01', $now));
        self::assertTrue(PointageTimestampValidator::isTooFarInFuture('2103-08-18 06:43:39', $now));
    }

    public function testUsesTheConfiguredFutureTolerance(): void
    {
        $now = new DateTimeImmutable('2026-11-10 08:00:00');

        self::assertTrue(PointageTimestampValidator::isValid('2026-11-10 08:07:00', $now, 600));
        self::assertTrue(PointageTimestampValidator::isTooFarInFuture('2026-11-10 08:07:00', $now, 300));
        self::assertFalse(PointageTimestampValidator::isValid('2026-11-10 08:00:01', $now, 0));
    }

    public function testRejectsInvalidAndSentinelDates(): void
    {
        $now = new DateTimeImmutable('2026-11-10 08:00:00');

        self::assertFalse(PointageTimestampValidator::isValid('2000-01-01 00:00:00', $now));
        self::assertFalse(PointageTimestampValidator::isValid('2026-02-30 08:00:00', $now));
        self::assertFalse(PointageTimestampValidator::isValid('2026-11-10', $now));
    }
}
