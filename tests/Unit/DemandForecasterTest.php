<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Reporting\Domain\DemandForecaster;
use PHPUnit\Framework\TestCase;

final class DemandForecasterTest extends TestCase
{
    public function test_holt_winters_follows_trend_and_seasonality(): void
    {
        $series = [];
        for ($t = 0; $t < 36; $t++) {
            $series[] = 200 + 3 * $t + 40 * sin(2 * M_PI * $t / 12);
        }
        $r = (new DemandForecaster)->forecast($series, 3);

        $this->assertSame('holt_winters', $r->method);
        foreach ([36, 37, 38] as $i => $t) {
            $expected = 200 + 3 * $t + 40 * sin(2 * M_PI * $t / 12);
            $this->assertEqualsWithDelta($expected, $r->forecast[$i], $expected * 0.08, "month +{$i}");
        }
        $this->assertLessThan(0.08, $r->wape);
    }

    public function test_holt_linear_extrapolates_a_trend_on_short_history(): void
    {
        $r = (new DemandForecaster)->forecast([100, 110, 121, 130, 140, 151, 160, 170], 2);
        $this->assertSame('holt_linear', $r->method);
        $this->assertEqualsWithDelta(180, $r->forecast[0], 12);
        $this->assertGreaterThan($r->forecast[0], $r->forecast[1]);
    }

    public function test_very_short_history_uses_the_mean_and_nothing_is_negative(): void
    {
        $this->assertSame('mean', (new DemandForecaster)->forecast([10, 20, 30], 2)->method);
        $this->assertSame([20.0, 20.0], (new DemandForecaster)->forecast([10, 20, 30], 2)->forecast);

        $falling = (new DemandForecaster)->forecast([100, 80, 60, 40, 20, 5, 1], 6);
        foreach ($falling->forecast as $v) {
            $this->assertGreaterThanOrEqual(0.0, $v);
        }
        $this->assertSame('none', (new DemandForecaster)->forecast([], 3)->method);
    }

    public function test_wape_is_only_reported_when_there_is_enough_history_to_test(): void
    {
        $this->assertNull((new DemandForecaster)->forecast([5, 6, 7, 8, 9, 10], 3)->wape);
        $this->assertNotNull((new DemandForecaster)->forecast(range(5, 16), 3)->wape);
    }
}
