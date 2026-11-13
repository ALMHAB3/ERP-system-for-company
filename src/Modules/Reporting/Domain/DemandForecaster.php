<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain;

/**
 * Monthly demand forecasting. The method follows the amount of history available, never more than it supports:
 *   >= 24 months  Holt-Winters additive (level + trend + yearly seasonality), parameters picked by grid search
 *   6..23 months  Holt linear trend
 *   1..5 months   mean of the available months
 * Forecasts are floored at zero. Pure statistics (floats are fine here: these are estimates, not accounting).
 */
final class DemandForecaster
{
    private const HOLD_OUT = 3;

    /** @param list<float|int> $series monthly demand, oldest first */
    public function forecast(array $series, int $horizon = 3): ForecastResult
    {
        $series = array_map('floatval', array_values($series));
        $n = count($series);
        if ($n === 0) {
            return new ForecastResult('none', array_fill(0, $horizon, 0.0), null, 0.0, 0.0, 0);
        }

        [$method, $forecast] = $this->fit($series, $horizon);
        $wape = null;
        if ($n >= self::HOLD_OUT + 6) {
            $train = array_slice($series, 0, $n - self::HOLD_OUT);
            [, $predicted] = $this->fit($train, self::HOLD_OUT);
            $actual = array_slice($series, -self::HOLD_OUT);
            $total = array_sum($actual);
            $error = 0.0;
            foreach ($actual as $i => $a) {
                $error += abs($a - $predicted[$i]);
            }
            $wape = $total > 0 ? $error / $total : null;
        }

        $mean = array_sum($series) / $n;
        $variance = $n > 1 ? array_sum(array_map(static fn ($x) => ($x - $mean) ** 2, $series)) / ($n - 1) : 0.0;

        return new ForecastResult($method, $forecast, $wape, $mean, sqrt($variance), $n);
    }

    /** @param list<float> $y @return array{0: string, 1: list<float>} */
    private function fit(array $y, int $horizon): array
    {
        $n = count($y);
        if ($n >= 24) {
            return ['holt_winters', $this->holtWinters($y, $horizon)];
        }
        if ($n >= 6) {
            return ['holt_linear', $this->holtLinear($y, $horizon)];
        }

        return ['mean', array_fill(0, $horizon, max(0.0, array_sum($y) / $n))];
    }

    /** @param list<float> $y @return list<float> */
    private function holtLinear(array $y, int $h): array
    {
        $best = null;
        foreach ([0.2, 0.4, 0.6] as $alpha) {
            foreach ([0.05, 0.15, 0.3] as $beta) {
                $level = $y[0];
                $trend = ($y[min(count($y) - 1, 2)] - $y[0]) / max(1, min(count($y) - 1, 2));
                $sse = 0.0;
                for ($t = 1, $n = count($y); $t < $n; $t++) {
                    $pred = $level + $trend;
                    $sse += ($y[$t] - $pred) ** 2;
                    $prev = $level;
                    $level = $alpha * $y[$t] + (1 - $alpha) * ($level + $trend);
                    $trend = $beta * ($level - $prev) + (1 - $beta) * $trend;
                }
                if ($best === null || $sse < $best[0]) {
                    $best = [$sse, $level, $trend];
                }
            }
        }
        [, $level, $trend] = $best;

        return array_map(static fn ($k) => max(0.0, $level + $k * $trend), range(1, $h));
    }

    /** @param list<float> $y @return list<float> */
    private function holtWinters(array $y, int $h): array
    {
        $m = 12;
        $n = count($y);
        $best = null;
        foreach ([0.1, 0.3, 0.5] as $alpha) {
            foreach ([0.01, 0.1] as $beta) {
                foreach ([0.1, 0.3, 0.5] as $gamma) {
                    $first = array_sum(array_slice($y, 0, $m)) / $m;
                    $second = array_sum(array_slice($y, $m, $m)) / $m;
                    $level = $first;
                    $trend = ($second - $first) / $m;
                    $season = [];
                    for ($i = 0; $i < $m; $i++) {
                        $season[$i] = $y[$i] - $first;
                    }
                    $sse = 0.0;
                    for ($t = $m; $t < $n; $t++) {
                        $s = $season[$t % $m];
                        $pred = $level + $trend + $s;
                        $sse += ($y[$t] - $pred) ** 2;
                        $prev = $level;
                        $level = $alpha * ($y[$t] - $s) + (1 - $alpha) * ($level + $trend);
                        $trend = $beta * ($level - $prev) + (1 - $beta) * $trend;
                        $season[$t % $m] = $gamma * ($y[$t] - $level) + (1 - $gamma) * $s;
                    }
                    if ($best === null || $sse < $best[0]) {
                        $best = [$sse, $level, $trend, $season];
                    }
                }
            }
        }
        [, $level, $trend, $season] = $best;

        return array_map(static fn ($k) => max(0.0, $level + $k * $trend + $season[($n + $k - 1) % $m]), range(1, $h));
    }
}
