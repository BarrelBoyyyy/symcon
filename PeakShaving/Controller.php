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
    public static function plan(float $grid, float $target, float $hysteresis, float $battery, float $charge, float $discharge, array $consumers, string $distribution = 'priority'): array
    {
        // Positive Abweichung: Bezug reduzieren. Negative Abweichung: freies Budget.
        $error = $grid - $target;
        if (abs($error) <= $hysteresis) {
            return ['battery' => null, 'consumers' => []];
        }
        $nextBattery = max(-$charge, min($discharge, $battery + $error));
        // Nur zusätzlich angeforderte Entladung reduziert den gemessenen Bezug.
        $remaining = $error - ($nextBattery - $battery);
        if (!in_array($distribution, ['priority', 'equal'], true)) {
            throw new RuntimeException('Ungültige Verbraucherverteilung');
        }
        if ($distribution === 'equal' && $consumers && abs($remaining) > 0.000000001) {
            // Gleichmäßige Gesamtleistung (nicht identische Stromwerte): begrenzte
            // Verbraucher ausschöpfen und deren übriges Budget auf die anderen verteilen.
            $budget = max(0, array_sum(array_column($consumers, 'actual')) - $remaining);
            $low = 0.0;
            $high = 0.0;
            foreach ($consumers as $c) {
                $high = max($high, $c['Max'] * self::factor($c));
            }
            for ($i = 0; $i < 64; $i++) {
                $level = ($low + $high) / 2;
                $used = 0.0;
                foreach ($consumers as $c) {
                    $used += min($level, $c['Max'] * self::factor($c));
                }
                if ($used > $budget) {
                    $high = $level;
                } else {
                    $low = $level;
                }
            }
            $commands = [];
            foreach ($consumers as $key => $c) {
                $wanted = min($c['Max'], $low / self::factor($c));
                $units = min($c['Max'], floor(($wanted + 0.000000001) / $c['Step']) * $c['Step']);
                if ($c['Unit'] === 'Switch') {
                    $units = $wanted + 0.000000001 >= $c['Max'] ? $c['Max'] : 0;
                }
                if ($units > 0 && $units < $c['Min']) {
                    $units = 0;
                }
                $commands[$key] = $c['Unit'] === 'Switch' ? ($units > 0 ? 1 : 0) : $units;
            }
            return ['battery' => $nextBattery, 'consumers' => $commands];
        }
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
