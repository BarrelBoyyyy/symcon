<?php

declare(strict_types=1);

final class PeakPlanner
{
    public static function factor(array $c): float
    {
        if ($c['Unit'] === 'A') {
            return (float) ($c['Voltage'] * $c['Phases'] * ($c['PowerFactor'] ?? 1));
        }
        if ($c['Unit'] === 'Scale') {
            return (float) ($c['MaxPower'] / $c['Max']);
        }
        return $c['Unit'] === 'W' && ($c['PerPhaseW'] ?? false) ? (float) $c['Phases'] : 1.0;
    }

    /**
     * Netzbezug positiv, Batterie-Entladung positiv; Laden jeweils negativ.
     * grid, target, battery, Limits und Verbraucher-Istwerte verwenden dieselbe
     * globale Leistungseinheit. factor() übersetzt A bzw. skalierte Sollwerte dorthin.
     * Der Netz-Istwert enthält die laufenden Lasten bereits: wir regeln nur Deltas.
     */
    public static function plan(float $grid, float $target, float $hysteresis, float $battery, float $charge, float $discharge, array $consumers): array
    {
        // Positive Abweichung: Bezug reduzieren. Negative Abweichung: freies Budget.
        $error = $grid - $target;
        if (abs($error) <= $hysteresis) {
            return ['battery' => null, 'consumers' => []];
        }
        $nextBattery = max(-$charge, min($discharge, $battery + $error));
        // Nur zusätzlich angeforderte Entladung reduziert den gemessenen Bezug.
        $remaining = $error - ($nextBattery - $battery);
        // Größere Prioritätswerte zuerst reduzieren, kleinere zuerst wieder versorgen.
        uasort($consumers, static function (array $a, array $b) use ($remaining): int {
            return $remaining > 0 ? $b['Priority'] <=> $a['Priority'] : $a['Priority'] <=> $b['Priority'];
        });
        $commands = [];
        $direction = $remaining;
        foreach ($consumers as $key => $c) {
            if (abs($remaining) < 0.000000001 || $remaining * $direction < 0) {
                break;
            }
            $factor = self::factor($c);
            $wanted = max(0, min($c['Max'], ($c['actual'] - $remaining) / $factor));
            // Abrunden verhindert eine Budgetüberschreitung durch die Schrittweite.
            $units = floor($wanted / $c['Step']) * $c['Step'];
            $units = min($c['Max'], $units);
            if ($c['Unit'] === 'Switch') {
                $units = $wanted >= $c['Max'] ? $c['Max'] : 0;
            }
            if ($units > 0 && $units < $c['Min']) {
                $units = 0;
            }
            // Minderverbrauch gleicht einen positiven Rest aus; Mehrverbrauch
            // verbraucht negatives Restbudget. Istwerte nicht doppelt subtrahieren.
            $remaining -= $c['actual'] - $units * $factor;
            $commands[$key] = $c['Unit'] === 'Switch' ? ($units > 0 ? 1 : 0) : $units;
        }
        return ['battery' => $nextBattery, 'consumers' => $commands];
    }
}
