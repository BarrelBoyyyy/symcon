<?php

declare(strict_types=1);

final class PeakPlanner
{
    public static function factor(array $c): float
    {
        if ($c['Unit'] === 'A') {
            return (float) ($c['Voltage'] * $c['Phases']);
        }
        if ($c['Unit'] === 'Scale') {
            return (float) ($c['MaxPower'] / $c['Max']);
        }
        return $c['Unit'] === 'W' && ($c['PerPhaseW'] ?? false) ? (float) $c['Phases'] : 1.0;
    }

    /** Positive battery power means discharge. Consumer actual/desired are watts. */
    public static function plan(float $grid, float $target, float $hysteresis, float $battery, float $charge, float $discharge, array $consumers): array
    {
        $error = $grid - $target;
        if (abs($error) <= $hysteresis) {
            return ['battery' => null, 'consumers' => []];
        }
        $nextBattery = max(-$charge, min($discharge, $battery + $error));
        $remaining = $error - ($nextBattery - $battery);
        // Larger priority values are shed first; smaller values are restored first.
        uasort($consumers, static function (array $a, array $b) use ($remaining): int {
            return $remaining > 0 ? $b['Priority'] <=> $a['Priority'] : $a['Priority'] <=> $b['Priority'];
        });
        $commands = [];
        $direction = $remaining;
        foreach ($consumers as $key => $c) {
            if (abs($remaining) < 0.001 || $remaining * $direction < 0) {
                break;
            }
            $factor = self::factor($c);
            $wanted = max(0, min($c['Max'], ($c['actual'] - $remaining) / $factor));
            // Round down so restoration never exceeds the available power budget.
            $units = floor($wanted / $c['Step']) * $c['Step'];
            $units = min($c['Max'], $units);
            if ($c['Unit'] === 'Switch') {
                $units = $wanted >= $c['Max'] ? $c['Max'] : 0;
            }
            if ($units > 0 && $units < $c['Min']) {
                $units = 0;
            }
            $remaining -= $c['actual'] - $units * $factor;
            $commands[$key] = $c['Unit'] === 'Switch' ? ($units > 0 ? 1 : 0) : $units;
        }
        return ['battery' => $nextBattery, 'consumers' => $commands];
    }
}
