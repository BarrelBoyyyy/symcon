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
        foreach (['GridPower' => 0, 'Interval' => 5, 'MaxAge' => 60, 'BatteryActual' => 0, 'BatterySetpoint' => 0, 'SOC' => 0, 'BatteryCharge' => 0, 'BatteryDischarge' => 0, 'BatteryStatus' => 0, 'BatteryReadyValue' => 1, 'BatteryModeVariable' => 0, 'BatteryChargingValue' => 1, 'BatteryDischargingValue' => 2, 'BatteryIdleValue' => 0] as $name => $value) {
            $this->RegisterPropertyInteger($name, $value);
        }
        foreach (['Target' => 5000, 'Hysteresis' => 100, 'MaxDischarge' => 3000, 'MaxCharge' => 3000, 'MinSOC' => 20, 'MaxSOC' => 90] as $name => $value) {
            $this->RegisterPropertyFloat($name, $value);
        }
        $this->RegisterPropertyString('Consumers', '[]');
        $this->RegisterVariableFloat('Grid', 'Netzbezug', '~Watt', 1);
        $this->RegisterVariableString('State', 'Regelstatus', '', 2);
        $this->RegisterVariableFloat('Projected', 'Geplanter Netzbezug', '~Watt', 3);
        $this->RegisterTimer('Control', 0, 'PS_Control($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $enabled = $this->ReadPropertyBoolean('Enabled');
        $this->SetTimerInterval('Control', $enabled ? max(1, $this->ReadPropertyInteger('Interval')) * 1000 : 0);
        $this->SetStatus($enabled ? 102 : 104);
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
        $typed = $v['VariableType'] === 0 ? (bool) $value : ($v['VariableType'] === 1 ? (int) round($value) : $value);
        if (abs((float) GetValue($id) - $typed) > 0.001) {
            if (!RequestAction($id, $typed)) {
                throw new RuntimeException('Aktion fehlgeschlagen: ' . $id);
            }
        }
    }

    public function Control(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled') || !IPS_SemaphoreEnter('PeakShaving_' . $this->InstanceID, 1)) {
            return;
        }
        try {
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
                $modeID = $this->ReadPropertyInteger('BatteryModeVariable');
                if ($modeID) {
                    $this->checkAction($modeID, IPS_VariableExists($modeID) && IPS_GetVariable($modeID)['VariableType'] === 0);
                    if (in_array($modeID, $ids)) {
                        throw new RuntimeException('Batterie-Umschaltvariable mehrfach verwendet');
                    }
                    $ids[] = $modeID;
                }
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
                $c = array_replace(['MaxPower' => 5000, 'PerPhaseW' => false, 'Condition' => '[]', 'ConditionHint' => '', 'MinRuntime' => 0, 'FollowUp' => 0, 'Delay' => 0, 'StopOnCondition' => true, 'Mode' => 'Auto'], $c);
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
                } elseif (abs(($c['Unit'] === 'A' && $c['ZeroToOne'] && $wanted == 0 ? 1 : $wanted) - $c['commanded']) > 0.001) {
                    $plan['consumers'][$key] = $wanted;
                } else {
                    unset($plan['consumers'][$key]);
                }
                $timing[$id] = $state;
            }
            // SOC protection applies even inside the grid deadband.
            if ($hasBattery) {
                $commandSign = $this->ReadPropertyBoolean('InvertBatteryCommand') ? -1 : 1;
                $chargeSign = $this->ReadPropertyBoolean('InvertCharge') ? -1 : 1;
                $dischargeSign = $this->ReadPropertyBoolean('InvertDischarge') ? -1 : 1;
                $commanded = $mode === 'signed' ? $commandSign * (float) GetValue($batteryID) : $dischargeSign * (float) GetValue($dischargeID) - $chargeSign * (float) GetValue($batteryID);
                $setpoint = $plan['battery'] ?? max(-$charge, min($discharge, $commanded));
                $modeID = $this->ReadPropertyInteger('BatteryModeVariable');
                if ($modeID) {
                    $modeValue = $setpoint > 0 ? $this->ReadPropertyInteger('BatteryDischargingValue') : ($setpoint < 0 ? $this->ReadPropertyInteger('BatteryChargingValue') : $this->ReadPropertyInteger('BatteryIdleValue'));
                    if ((float) GetValue($modeID) != $modeValue) {
                        $this->writeAction($batteryID, 0);
                        if ($mode === 'separate') {
                            $this->writeAction($dischargeID, 0);
                        }
                        if (IPS_GetVariable($modeID)['VariableType'] === 0 && !in_array($modeValue, [0, 1], true)) {
                            throw new RuntimeException('Boolesche Umschaltvariable erlaubt nur 0 oder 1');
                        }
                        $this->writeAction($modeID, $modeValue);
                    }
                }
                if ($mode === 'signed') {
                    $this->writeAction($batteryID, $commandSign * $setpoint);
                } elseif ($setpoint >= 0) {
                    // Stop the opposite direction first. Abort if its action fails.
                    $this->writeAction($batteryID, 0);
                    $this->writeAction($dischargeID, $dischargeSign * $setpoint);
                } else {
                    $this->writeAction($dischargeID, 0);
                    $this->writeAction($batteryID, $chargeSign * -$setpoint);
                }
            }
            $projected = $grid - ($hasBattery ? $setpoint - $battery : 0);
            foreach ($plan['consumers'] as $key => $value) {
                $c = $consumers[$key];
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
            if ($projected > $this->ReadPropertyFloat('Target') + $this->ReadPropertyFloat('Hysteresis')) {
                $notes[] = 'Zielnetzbezug mit aktuellen Freigaben / Grenzen nicht erreichbar';
            }
            $this->SetValue('State', $notes ? implode('; ', array_unique($notes)) : (abs($grid - $this->ReadPropertyFloat('Target')) <= $this->ReadPropertyFloat('Hysteresis') ? 'Innerhalb Hysterese' : 'Sollwerte angepasst'));
            $this->SetStatus(102);
        } catch (Throwable $e) {
            $this->SetValue('State', $e->getMessage());
            $this->SetStatus(200);
            $this->SendDebug('Regelung gestoppt', $e->getMessage(), 0);
        } finally {
            IPS_SemaphoreLeave('PeakShaving_' . $this->InstanceID);
        }
    }
}
