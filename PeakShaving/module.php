<?php

declare(strict_types=1);
require_once __DIR__ . '/Controller.php';

class PeakShaving extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Enabled', false);
        $this->RegisterPropertyBoolean('InvertGrid', false);
        $this->RegisterPropertyBoolean('PeakOverridesHold', true);
        $this->RegisterPropertyBoolean('InvertBattery', false);
        $this->RegisterPropertyBoolean('InvertBatteryCommand', false);
        $this->RegisterPropertyBoolean('InvertCharge', false);
        $this->RegisterPropertyBoolean('InvertDischarge', false);
        $this->RegisterPropertyString('BatteryMode', 'separate');
        $this->RegisterPropertyString('PowerUnit', 'W');
        foreach (['GridPower' => 0, 'Interval' => 5, 'MaxAge' => 60, 'BatteryActual' => 0, 'BatterySetpoint' => 0, 'SOC' => 0, 'BatteryCharge' => 0, 'BatteryDischarge' => 0, 'BatteryStatus' => 0, 'BatteryReadyValue' => 1] as $name => $value) {
            $this->RegisterPropertyInteger($name, $value);
        }
        foreach (['Target' => 5000, 'Hysteresis' => 100, 'MaxDischarge' => 3000, 'MaxCharge' => 3000, 'MinSOC' => 20, 'MaxSOC' => 90] as $name => $value) {
            $this->RegisterPropertyFloat($name, $value);
        }
        $this->RegisterPropertyString('Consumers', '[]');
        $this->RegisterAttributeString('LoadIDs', '[]');
        $this->RegisterAttributeString('OutputUnit', 'W');
        $this->RegisterVariableFloat('Grid', $this->Translate('Netzbezug'), '~Watt', 1);
        $this->RegisterVariableString('State', $this->Translate('Regelstatus'), '', 2);
        $this->RegisterVariableFloat('Projected', $this->Translate('Geplanter Netzbezug'), '~Watt', 3);
        $this->RegisterVariableBoolean('PeakActive', $this->Translate('Peak Shaving aktiv'), '~Switch', 4);
        $this->RegisterTimer('Control', 0, 'PS_Control($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        try {
            $profile = $this->powerProfile();
            $this->RegisterVariableFloat('Grid', $this->Translate('Netzbezug'), $profile, 1);
            $this->RegisterVariableFloat('Projected', $this->Translate('Geplanter Netzbezug'), $profile, 3);
            $this->SetValue('PeakActive', false);
            $this->syncLoads($profile);
            // Vorhandene Statuswerte beim Profilwechsel ebenfalls umrechnen.
            // Externe Variablen und Konfigurationswerte bleiben unter Nutzerkontrolle.
            $previousFactor = $this->ReadAttributeString('OutputUnit') === 'kW' ? 0.001 : 1.0;
            $ratio = $this->powerFactor() / $previousFactor;
            if ($ratio !== 1.0) {
                foreach (['Grid', 'Projected'] as $ident) {
                    $this->SetValue($ident, $this->GetValue($ident) * $ratio);
                }
                foreach (json_decode($this->ReadAttributeString('LoadIDs'), true) as $id) {
                    $ident = 'LoadPower_' . $id;
                    $this->SetValue($ident, $this->GetValue($ident) * $ratio);
                }
            }
            $this->WriteAttributeString('OutputUnit', $this->ReadPropertyString('PowerUnit'));
            $enabled = $this->ReadPropertyBoolean('Enabled');
            $this->SetTimerInterval('Control', $enabled ? max(1, $this->ReadPropertyInteger('Interval')) * 1000 : 0);
            $this->SetStatus($enabled ? 102 : 104);
        } catch (Throwable $e) {
            $this->SetTimerInterval('Control', 0);
            $this->SetValue('PeakActive', false);
            $this->SetValue('State', $e->getMessage());
            $this->SetStatus(200);
        }
    }

    private function powerFactor(): float
    {
        $unit = $this->ReadPropertyString('PowerUnit');
        if (!in_array($unit, ['W', 'kW'], true)) {
            throw new RuntimeException('Leistungseinheit muss W oder kW sein');
        }
        return $unit === 'kW' ? 0.001 : 1.0;
    }

    private function powerProfile(): string
    {
        $this->powerFactor();
        $unit = $this->ReadPropertyString('PowerUnit');
        $name = 'PS.Power.' . $unit;
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 2);
            IPS_SetVariableProfileText($name, '', ' ' . $unit);
            IPS_SetVariableProfileDigits($name, $unit === 'kW' ? 3 : 0);
            IPS_SetVariableProfileValues($name, 0, 0, 0);
        }
        return $name;
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $mode = $this->ReadPropertyString('BatteryMode');
        $unit = $this->ReadPropertyString('PowerUnit');
        foreach ($form['elements'] as &$element) {
            $name = $element['name'] ?? '';
            if (in_array($name, ['BatterySetpoint', 'InvertBatteryCommand'], true)) {
                $element['visible'] = $mode === 'signed';
            }
            if (in_array($name, ['BatteryCharge', 'BatteryDischarge', 'InvertCharge', 'InvertDischarge'], true)) {
                $element['visible'] = $mode === 'separate';
            }
            if (isset($element['caption'])) {
                $element['caption'] = str_replace('{unit}', $unit, $this->Translate($element['caption']));
            }
            if ($name === 'Consumers') {
                $element = $this->loadFormUnit($element, $unit);
            }
        }
        unset($element);
        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    private function loadFormUnit(array $element, string $unit): array
    {
        foreach ($element['columns'] as &$column) {
            $column['caption'] = str_replace('{unit}', $unit, $this->Translate($column['caption']));
            if (in_array($column['name'], ['Max', 'Step', 'MaxPower'], true)) {
                $column['add'] *= $unit === 'kW' ? 0.001 : 1;
            }
            if ($column['name'] === 'Unit') {
                $column['edit']['options'][0]['caption'] = $unit;
            }
        }
        unset($column);
        return $element;
    }

    public function ConfigureForm(string $mode, string $unit): void
    {
        foreach (['BatterySetpoint', 'InvertBatteryCommand'] as $name) {
            $this->UpdateFormField($name, 'visible', $mode === 'signed');
        }
        foreach (['BatteryCharge', 'BatteryDischarge', 'InvertCharge', 'InvertDischarge'] as $name) {
            $this->UpdateFormField($name, 'visible', $mode === 'separate');
        }
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($form['elements'] as $element) {
            if (isset($element['name'], $element['caption'])) {
                $this->UpdateFormField($element['name'], 'caption', str_replace('{unit}', $unit, $this->Translate($element['caption'])));
            }
            if (($element['name'] ?? '') === 'Consumers') {
                $this->UpdateFormField('Consumers', 'columns', json_encode($this->loadFormUnit($element, $unit)['columns'], JSON_THROW_ON_ERROR));
            }
        }
    }

    private function modeCaption(string $mode): string
    {
        return ['Auto' => 'Automatisch', 'On' => 'Aktiv', 'Off' => 'Inaktiv'][$mode] ?? 'Ungültiger Modus';
    }

    private function syncLoads(string $profile): void
    {
        $loads = json_decode($this->ReadPropertyString('Consumers'), true, 512, JSON_THROW_ON_ERROR);
        $current = [];
        foreach ($loads as $load) {
            $id = (int) ($load['Setpoint'] ?? 0);
            if ($id <= 0 || in_array($id, $current, true)) {
                continue;
            }
            $current[] = $id;
            $name = ($load['Name'] ?? '') ?: $this->Translate('Verbraucher') . ' ' . $id;
            $this->RegisterVariableBoolean('LoadActive_' . $id, $name . ': ' . $this->Translate('Aktiv'), '~Switch', 100);
            $this->RegisterVariableFloat('LoadPower_' . $id, $name . ': ' . $this->Translate('Leistung'), $profile, 101);
            $this->RegisterVariableString('LoadState_' . $id, $name . ': ' . $this->Translate('Status'), '', 102);
            $this->RegisterVariableString('LoadMode_' . $id, $name . ': ' . $this->Translate('Modus'), '', 103);
            $this->RegisterVariableInteger('LoadPriority_' . $id, $name . ': ' . $this->Translate('Priorität'), '', 104);
            $this->SetValue('LoadMode_' . $id, $this->modeCaption($load['Mode'] ?? 'Auto'));
            $this->SetValue('LoadPriority_' . $id, (int) ($load['Priority'] ?? 1));
        }
        $previous = json_decode($this->ReadAttributeString('LoadIDs') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        foreach (array_diff($previous, $current) as $id) {
            foreach (['LoadActive_', 'LoadPower_', 'LoadState_', 'LoadMode_', 'LoadPriority_'] as $prefix) {
                $this->UnregisterVariable($prefix . $id);
            }
        }
        $this->WriteAttributeString('LoadIDs', json_encode($current, JSON_THROW_ON_ERROR));
    }

    private function updateLoadStatus(array $loads, array $commands): void
    {
        foreach ($loads as $key => $load) {
            $id = (int) $load['Setpoint'];
            $command = $commands[$key] ?? $load['commanded'];
            $active = $load['Unit'] === 'A' && $load['ZeroToOne'] ? $command > 1 : $command > 0;
            $this->SetValue('LoadActive_' . $id, $active);
            $this->SetValue('LoadPower_' . $id, $load['actual']);
            $this->SetValue('LoadMode_' . $id, $this->modeCaption($load['Mode']));
            $this->SetValue('LoadPriority_' . $id, (int) $load['Priority']);
            $state = !$load['allowed'] ? ($load['ConditionHint'] ?: 'Durch Bedingung gesperrt') : ($active ? 'Aktiv' : 'Inaktiv');
            $this->SetValue('LoadState_' . $id, $this->Translate($state));
        }
    }

    private function readMeasurement(int $id): float
    {
        if (!$id || !IPS_VariableExists($id)) {
            throw new RuntimeException('Messvariable fehlt: ' . $id);
        }
        $meta = IPS_GetVariable($id);
        if (!in_array($meta['VariableType'], [1, 2], true)) {
            throw new RuntimeException('Messvariable muss numerisch sein: ' . $id);
        }
        if (time() - $meta['VariableUpdated'] > $this->ReadPropertyInteger('MaxAge')) {
            throw new RuntimeException('Messwert ist veraltet: ' . $id);
        }
        $value = (float) GetValue($id);
        if (!is_finite($value)) {
            throw new RuntimeException('Ungültiger Messwert: ' . $id);
        }
        return $value;
    }

    private function checkAction(int $id, bool $switch = false): void
    {
        if (!$id || !IPS_VariableExists($id)) {
            throw new RuntimeException('Aktionsvariable fehlt: ' . $id);
        }
        $v = IPS_GetVariable($id);
        if (!in_array($v['VariableType'], $switch ? [0] : [1, 2], true) || !($v['VariableCustomAction'] ?: $v['VariableAction'])) {
            throw new RuntimeException('Numerische Variable mit Aktion erforderlich: ' . $id);
        }
    }

    private function writeAction(int $id, float $value): void
    {
        $v = IPS_GetVariable($id);
        if ($v['VariableType'] === 1 && abs($value - round($value)) > 0.000000001) {
            throw new RuntimeException('Ganzzahl-Aktionsvariable kann diesen Sollwert nicht darstellen: ' . $id);
        }
        $typed = $v['VariableType'] === 0 ? (bool) $value : ($v['VariableType'] === 1 ? (int) round($value) : $value);
        if (abs((float) GetValue($id) - $typed) > 0.000000001) {
            if (!RequestAction($id, $typed)) {
                throw new RuntimeException('Aktion fehlgeschlagen: ' . $id);
            }
        }
    }

    public function Control(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled')) {
            $this->SetValue('PeakActive', false);
            return;
        }
        if (!IPS_SemaphoreEnter('PeakShaving_' . $this->InstanceID, 1)) {
            return;
        }
        try {
            $this->powerFactor();
            if ($this->ReadPropertyFloat('Hysteresis') < 0 || $this->ReadPropertyInteger('MaxAge') < 1 || $this->ReadPropertyFloat('MaxCharge') < 0 || $this->ReadPropertyFloat('MaxDischarge') < 0) {
                throw new RuntimeException('Grenzen, Messwertalter oder Hysterese ungültig');
            }
            $grid = ($this->ReadPropertyBoolean('InvertGrid') ? -1 : 1) * $this->readMeasurement($this->ReadPropertyInteger('GridPower'));
            $timing = json_decode($this->GetBuffer('Timing') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            $now = time();
            $mode = $this->ReadPropertyString('BatteryMode');
            if (!in_array($mode, ['separate', 'signed'], true)) {
                throw new RuntimeException('Batteriemodus ungültig');
            }
            $batteryID = $mode === 'signed' ? $this->ReadPropertyInteger('BatterySetpoint') : $this->ReadPropertyInteger('BatteryCharge');
            $dischargeID = $this->ReadPropertyInteger('BatteryDischarge');
            $hasBattery = $batteryID || ($mode === 'separate' && $dischargeID);
            $sign = $this->ReadPropertyBoolean('InvertBattery') ? -1 : 1;
            $battery = 0.0;
            $charge = $discharge = 0.0;
            $ids = [];
            if ($hasBattery) {
                $this->checkAction($batteryID);
                $ids[] = $batteryID;
                if ($mode === 'separate') {
                    $this->checkAction($dischargeID);
                    if ($dischargeID === $batteryID) {
                        throw new RuntimeException('Lade- und Entladevariable müssen verschieden sein');
                    }
                    $ids[] = $dischargeID;
                }
                $statusID = $this->ReadPropertyInteger('BatteryStatus');
                if ($statusID) {
                    if (!IPS_VariableExists($statusID)) {
                        throw new RuntimeException('Batterie-Statusvariable fehlt');
                    }
                    $meta = IPS_GetVariable($statusID);
                    if (time() - $meta['VariableUpdated'] > $this->ReadPropertyInteger('MaxAge') || !in_array($meta['VariableType'], [0, 1, 2], true) || (float) GetValue($statusID) !== (float) $this->ReadPropertyInteger('BatteryReadyValue')) {
                        throw new RuntimeException('Batterie nicht bereit oder Status veraltet');
                    }
                }
                $commandSign = $this->ReadPropertyBoolean('InvertBatteryCommand') ? -1 : 1;
                $chargeSign = $this->ReadPropertyBoolean('InvertCharge') ? -1 : 1;
                $dischargeSign = $this->ReadPropertyBoolean('InvertDischarge') ? -1 : 1;
                $actualID = $this->ReadPropertyInteger('BatteryActual');
                $battery = $actualID ? $sign * $this->readMeasurement($actualID) : ($mode === 'signed' ? $commandSign * (float) GetValue($batteryID) : $dischargeSign * (float) GetValue($dischargeID) - $chargeSign * (float) GetValue($batteryID));
                $soc = $this->readMeasurement($this->ReadPropertyInteger('SOC'));
                $min = $this->ReadPropertyFloat('MinSOC');
                $max = $this->ReadPropertyFloat('MaxSOC');
                if ($min < 0 || $max > 100 || $min >= $max || $soc < 0 || $soc > 100) {
                    throw new RuntimeException('SOC oder SOC-Grenzen ungültig');
                }
                $charge = $soc < $max ? $this->ReadPropertyFloat('MaxCharge') : 0;
                $discharge = $soc > $min ? $this->ReadPropertyFloat('MaxDischarge') : 0;
            }
            $consumers = json_decode($this->ReadPropertyString('Consumers'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($consumers)) {
                throw new RuntimeException('Verbraucherliste ungültig');
            }
            foreach ($consumers as &$c) {
                $c = array_replace(['MaxPower' => 5000 * $this->powerFactor(), 'PerPhaseW' => false, 'Condition' => '[]', 'ConditionHint' => '', 'MinRuntime' => 0, 'FollowUp' => 0, 'Delay' => 0, 'StopOnCondition' => true, 'Mode' => 'Auto', 'PowerFactor' => $this->powerFactor()], $c);
                $this->checkAction((int) $c['Setpoint'], $c['Unit'] === 'Switch');
                if (in_array($c['Setpoint'], $ids)) {
                    throw new RuntimeException('Aktionsvariable mehrfach zugeordnet');
                }
                $ids[] = $c['Setpoint'];
                if (!in_array($c['Unit'], ['A', 'W', 'Switch', 'Scale'], true) || $c['MaxPower'] <= 0 || $c['MinRuntime'] < 0 || $c['FollowUp'] < 0 || $c['Delay'] < 0 || !in_array($c['Mode'], ['Auto', 'On', 'Off'], true) || $c['Step'] <= 0 || $c['Min'] < 0 || $c['Max'] <= 0 || $c['Max'] < $c['Min'] || (($c['Unit'] === 'A' || $c['PerPhaseW']) && ($c['Voltage'] <= 0 || !in_array($c['Phases'], [1, 2, 3])))) {
                    throw new RuntimeException('Verbrauchergrenzen / Einheit ungültig');
                }
                $c['commanded'] = (float) GetValue((int) $c['Setpoint']);
                $c['actual'] = $c['ActualPower'] ? $this->readMeasurement((int) $c['ActualPower']) : ($c['Unit'] === 'Switch' ? ($c['commanded'] ? $c['Max'] : 0) : ($c['Unit'] === 'A' && $c['ZeroToOne'] && $c['commanded'] <= 1 ? 0 : $c['commanded'] * PeakPlanner::factor($c)));
                $condition = is_string($c['Condition']) ? $c['Condition'] : json_encode($c['Condition'], JSON_THROW_ON_ERROR);
                $c['allowed'] = $condition === '[]' || $condition === '' || IPS_IsConditionPassing($condition);
                if ($c['actual'] < 0) {
                    throw new RuntimeException('Verbraucherleistung muss positiv sein');
                }
            }
            unset($c);
            $automatic = array_filter($consumers, static fn(array $c): bool => $c['Mode'] === 'Auto' && $c['allowed']);
            $plan = PeakPlanner::plan($grid, $this->ReadPropertyFloat('Target'), $this->ReadPropertyFloat('Hysteresis'), $battery, $charge, $discharge, $automatic);
            $override = $this->ReadPropertyBoolean('PeakOverridesHold') && $grid > $this->ReadPropertyFloat('Target') + $this->ReadPropertyFloat('Hysteresis');
            $notes = [];
            foreach ($consumers as $key => $c) {
                $id = (string) $c['Setpoint'];
                $state = $timing[$id] ?? ['started' => 0, 'deficit' => 0, 'changed' => 0];
                $active = $c['Unit'] === 'A' && $c['ZeroToOne'] ? $c['commanded'] > 1 : $c['commanded'] > 0;
                if ($active && !$state['started']) {
                    $state['started'] = $now;
                } elseif (!$active) {
                    $state['started'] = 0;
                }
                $wanted = $plan['consumers'][$key] ?? $c['commanded'];
                if ($c['Mode'] !== 'Auto') {
                    $wanted = $c['Mode'] === 'On' ? ($c['Unit'] === 'Switch' ? 1 : $c['Max']) : 0;
                } elseif (!$c['allowed']) {
                    $wanted = 0;
                    $notes[] = $c['ConditionHint'] ?: 'Verbraucher durch Bedingung gesperrt';
                }
                $forceStop = $c['Mode'] === 'Off' || (!$c['allowed'] && $c['StopOnCondition']);
                if ($wanted == 0 && $active) {
                    $state['deficit'] = $state['deficit'] ?: $now;
                    $held = $now < $state['started'] + $c['MinRuntime'] || $now < $state['deficit'] + $c['FollowUp'];
                    if ($held && !$forceStop && !$override) {
                        $wanted = $c['Unit'] === 'Switch' ? 1 : min($c['Max'], max($c['Min'], $c['Step']));
                        $notes[] = 'Verbraucher durch Laufzeitbindung gehalten';
                    }
                } else {
                    $state['deficit'] = 0;
                }
                if ($now < $state['changed'] + $c['Delay'] && !$forceStop && !$override) {
                    unset($plan['consumers'][$key]);
                } elseif (abs(($c['Unit'] === 'A' && $c['ZeroToOne'] && $wanted == 0 ? 1 : $wanted) - $c['commanded']) > 0.000000001) {
                    $plan['consumers'][$key] = $wanted;
                } else {
                    unset($plan['consumers'][$key]);
                }
                $timing[$id] = $state;
            }
            // SOC-Grenzen gelten auch im Totbereich. Eine gemeinsame Variable
            // benötigt keinen Moduswechsel: Das konfigurierbare Vorzeichen bestimmt die Richtung.
            if ($hasBattery) {
                $commandSign = $this->ReadPropertyBoolean('InvertBatteryCommand') ? -1 : 1;
                $chargeSign = $this->ReadPropertyBoolean('InvertCharge') ? -1 : 1;
                $dischargeSign = $this->ReadPropertyBoolean('InvertDischarge') ? -1 : 1;
                $commanded = $mode === 'signed' ? $commandSign * (float) GetValue($batteryID) : $dischargeSign * (float) GetValue($dischargeID) - $chargeSign * (float) GetValue($batteryID);
                $setpoint = $plan['battery'] ?? max(-$charge, min($discharge, $commanded));
                if ($mode === 'signed') {
                    $this->writeAction($batteryID, $commandSign * $setpoint);
                } elseif ($setpoint >= 0) {
                    // Bei getrennten Variablen zuerst die Gegenrichtung stoppen;
                    // ein fehlgeschlagener Stopp verhindert den neuen Richtungsbefehl.
                    $this->writeAction($batteryID, 0);
                    $this->writeAction($dischargeID, $dischargeSign * $setpoint);
                } else {
                    $this->writeAction($dischargeID, 0);
                    $this->writeAction($batteryID, $chargeSign * -$setpoint);
                }
            }
            // Netz-Istleistung enthält die bisherige Batterie- und Verbraucherleistung bereits.
            // Mehr Entladung senkt den Netzbezug: nur die Änderung (Soll minus Ist)
            // abziehen, niemals die gesamte Batterieleistung ein zweites Mal.
            // Alle Leistungsgrößen sind in der ausgewählten globalen Einheit W oder kW.
            $projected = $grid - ($hasBattery ? $setpoint - $battery : 0);
            foreach ($plan['consumers'] as $key => $value) {
                $c = $consumers[$key];
                // Ein erhöhter Verbrauch erhöht den Netzbezug um Sollverbrauch minus Istverbrauch.
                $projected += ($c['Unit'] === 'Switch' ? ($value ? $c['Max'] : 0) : $value * PeakPlanner::factor($c)) - $c['actual'];
                if ($c['Unit'] === 'A' && $c['ZeroToOne'] && $value == 0) {
                    $value = 1;
                }
                $this->writeAction((int) $c['Setpoint'], $value);
                $id = (string) $c['Setpoint'];
                $timing[$id]['changed'] = $now;
                if (!$timing[$id]['started'] && $value > 0 && !($c['Unit'] === 'A' && $c['ZeroToOne'] && $value == 1)) {
                    $timing[$id]['started'] = $now;
                }
            }
            $this->SetBuffer('Timing', json_encode($timing, JSON_THROW_ON_ERROR));
            $this->SetValue('Grid', $grid);
            $this->SetValue('Projected', $projected);
            // Aktiv bedeutet: aktuell gemessener Bezug liegt über Ziel plus Hysterese
            // und dieser Regelzyklus wurde erfolgreich ausgeführt, nicht nur Timer aktiviert.
            $this->SetValue('PeakActive', $grid > $this->ReadPropertyFloat('Target') + $this->ReadPropertyFloat('Hysteresis'));
            $this->updateLoadStatus($consumers, $plan['consumers']);
            if ($projected > $this->ReadPropertyFloat('Target') + $this->ReadPropertyFloat('Hysteresis')) {
                $notes[] = 'Zielnetzbezug mit aktuellen Freigaben / Grenzen nicht erreichbar';
            }
            $this->SetValue('State', $notes ? implode('; ', array_unique($notes)) : (abs($grid - $this->ReadPropertyFloat('Target')) <= $this->ReadPropertyFloat('Hysteresis') ? 'Innerhalb Hysterese' : 'Sollwerte angepasst'));
            $this->SetStatus(102);
        } catch (Throwable $e) {
            $this->SetValue('PeakActive', false);
            $this->SetValue('State', $e->getMessage());
            $this->SetStatus(200);
            $this->SendDebug('Regelung gestoppt', $e->getMessage(), 0);
        } finally {
            IPS_SemaphoreLeave('PeakShaving_' . $this->InstanceID);
        }
    }
}
