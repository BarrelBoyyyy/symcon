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
    public static function plan(float $grid, float $target, float $hysteresis, float $battery, float $charge, float $discharge, array $consumers, string $distribution = 'priority', bool $batteryBuffer = true): array
    {
        // Die Netzleistung enthält Lasten und Batterie bereits. Im Totbereich
        // wird eine kleine Netzabweichung ignoriert, nicht das nutzbare Batteriebudget.
        $error = $grid - $target;
        if (abs($error) <= $hysteresis && !$consumers) {
            return ['battery' => null, 'consumers' => []];
        }
        $effectiveError = abs($error) <= $hysteresis ? 0.0 : $error;
        if (!$consumers) {
            return ['battery' => max(-$charge, min($discharge, $battery + $effectiveError)), 'consumers' => []];
        }
        // Für die Lastzuteilung ist die zulässige Entladung ein Puffer. Dieser
        // Wert ist nur eine Budgetgrenze: geschrieben wird danach der tatsächliche Bedarf.
        if ($batteryBuffer) {
            $nextBattery = $discharge;
        } elseif ($effectiveError > 0) {
            $nextBattery = max(-$charge, min($discharge, $battery + $effectiveError));
        } else {
            // Ohne aktive Pufferfreigabe zuerst laufende Entladung zurücknehmen
            // und Ladeleistung für Verbraucher freigeben, aber keine neue Entladung starten.
            $nextBattery = max(0, min($discharge, $battery + $effectiveError));
        }
        $remaining = $effectiveError - ($nextBattery - $battery);
        if (!in_array($distribution, ['priority', 'equal'], true)) {
            throw new RuntimeException('Ungültige Verbraucherverteilung');
        }
        if ($distribution === 'equal' && $consumers) {
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
            return self::finish($effectiveError, $battery, $charge, $discharge, $consumers, $commands);
        }
        // Das gesamte Lastbudget nach Reihenfolge neu verteilen, auch bei
        // ausgeschöpftem Puffer: sonst könnte Drag & Drop keine Leistung verschieben.
        $budget = max(0, array_sum(array_column($consumers, 'actual')) - $remaining);
        uasort($consumers, static fn(array $a, array $b): int => $a['Priority'] <=> $b['Priority']);
        $commands = [];
        foreach ($consumers as $key => $c) {
            $factor = self::factor($c);
            $wanted = min($c['Max'], $budget / $factor);
            $units = min($c['Max'], floor(($wanted + 0.000000001) / $c['Step']) * $c['Step']);
            if ($c['Unit'] === 'Switch') {
                $units = $wanted + 0.000000001 >= $c['Max'] ? $c['Max'] : 0;
            }
            if ($units > 0 && $units < $c['Min']) {
                $units = 0;
            }
            $budget -= $units * $factor;
            $value = $c['Unit'] === 'Switch' ? ($units > 0 ? 1 : 0) : $units;
            $changed = isset($c['commanded']) ? abs($value - $c['commanded']) > 0.000000001 : abs($units * $factor - $c['actual']) > 0.000000001;
            if ($changed) {
                $commands[$key] = $value;
            }
        }
        return self::finish($effectiveError, $battery, $charge, $discharge, $consumers, $commands);
    }

    private static function finish(float $error, float $battery, float $charge, float $discharge, array $consumers, array $commands): array
    {
        // Nur benötigte Batterieleistung anfordern: Mehrverbrauch durch Lasten
        // plus Netzabweichung, zusätzlich zur bereits gemessenen Batterie-Leistung.
        $needed = $battery + $error;
        foreach ($commands as $key => $command) {
            $c = $consumers[$key];
            $power = $c['Unit'] === 'Switch' ? ($command ? $c['Max'] : 0) : $command * self::factor($c);
            $needed += $power - $c['actual'];
        }
        return ['battery' => max(-$charge, min($discharge, $needed)), 'consumers' => $commands];
    }

}
