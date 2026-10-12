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
        $this->RegisterPropertyBoolean('BatteryBuffer', true);
        $this->RegisterPropertyBoolean('InvertBattery', false);
        $this->RegisterPropertyBoolean('InvertBatteryCommand', false);
        $this->RegisterPropertyBoolean('InvertCharge', false);
        $this->RegisterPropertyBoolean('InvertDischarge', false);
        $this->RegisterPropertyString('BatteryMode', 'separate');
        $this->RegisterPropertyString('PowerUnit', 'W');
        $this->RegisterPropertyString('Distribution', 'priority');
        $this->RegisterPropertyString('UpdateMode', 'timer');
        foreach (['GridPower' => 0, 'Interval' => 5, 'MaxAge' => 60, 'BatteryActual' => 0, 'BatterySetpoint' => 0, 'SOC' => 0, 'BatteryCharge' => 0, 'BatteryDischarge' => 0] as $name => $value) {
            $this->RegisterPropertyInteger($name, $value);
        }
        foreach (['Target' => 5000, 'Hysteresis' => 100, 'MaxDischarge' => 3000, 'MaxCharge' => 3000, 'MinSOC' => 20, 'MaxSOC' => 90] as $name => $value) {
            $this->RegisterPropertyFloat($name, $value);
        }
        $this->RegisterPropertyString('Consumers', '[]');
        $this->RegisterAttributeString('LoadIDs', '[]');
        $this->RegisterAttributeString('OutputUnit', 'W');
        $this->RegisterAttributeString('LoadOverrides', '{}');
        $this->RegisterAttributeString('LoadConfig', '');
        $this->RegisterAttributeString('Trace', '[]');
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeString('LastRun', '');
        $this->RegisterAttributeString('SubscribedGrid', '0');
        $this->RegisterVariableFloat('Grid', $this->Translate('Netzbezug'), '~Watt', 1);
        $this->RegisterVariableString('State', $this->Translate('Regelstatus'), '', 2);
        $this->RegisterVariableFloat('Projected', $this->Translate('Geplanter Netzbezug'), '~Watt', 3);
        $this->RegisterVariableString('Diagnostics', 'Diagnosebericht', '', 5);
        $this->RegisterVariableBoolean('PeakActive', $this->Translate('Peak Shaving aktiv'), '~Switch', 4);
        $this->RegisterTimer('Control', 0, 'PS_Control($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        try {
            if (method_exists($this, 'SetVisualizationType')) {
                $this->SetVisualizationType(1);
            }
            $profile = $this->powerProfile();
            $this->RegisterVariableFloat('Grid', $this->Translate('Netzbezug'), $profile, 1);
            $this->RegisterVariableFloat('Projected', $this->Translate('Geplanter Netzbezug'), $profile, 3);
            $this->SetValue('PeakActive', false);
            $config = $this->ReadPropertyString('Consumers');
            if ($config !== $this->ReadAttributeString('LoadConfig')) {
                $this->WriteAttributeString('LoadOverrides', '{}');
                $this->WriteAttributeString('LoadConfig', $config);
            }
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
            $oldGrid = (int) $this->ReadAttributeString('SubscribedGrid');
            if ($oldGrid) {
                $this->UnregisterMessage($oldGrid, VM_UPDATE);
            }
            $this->WriteAttributeString('SubscribedGrid', '0');
            $updateMode = $this->ReadPropertyString('UpdateMode');
            if (!in_array($updateMode, ['timer', 'change'], true)) {
                throw new RuntimeException('Ungültiger Aktualisierungsmodus');
            }
            $gridID = $this->ReadPropertyInteger('GridPower');
            if ($enabled && $updateMode === 'change' && (!$gridID || !IPS_VariableExists($gridID))) {
                throw new RuntimeException('Für die Regelung bei Änderung muss eine Netzvariable ausgewählt sein');
            }
            if ($enabled && $updateMode === 'change' && $gridID && IPS_VariableExists($gridID)) {
                $this->RegisterMessage($gridID, VM_UPDATE);
                $this->WriteAttributeString('SubscribedGrid', (string) $gridID);
            }
            $this->SetTimerInterval('Control', $enabled && $updateMode === 'timer' ? max(1, $this->ReadPropertyInteger('Interval')) * 1000 : 0);
            $this->SetStatus($enabled ? 102 : 104);
            $this->publishVisualization();
        } catch (Throwable $e) {
            $this->SetTimerInterval('Control', 0);
            $this->SetValue('PeakActive', false);
            $this->SetValue('State', $e->getMessage());
$this->WriteAttributeString('LastError', $e->getMessage());
            $this->trace('Fehler', ['Meldung' => $e->getMessage()]);
            $this->SetStatus(200);
        }
    }

    public function ConfigureCondition(bool $enabled): void
    {
        // Der ausgeblendete Editor darf ohne Bedingung keine Variablenauswahl erzwingen.
        foreach (['Condition', 'ConditionHint', 'StopOnCondition'] as $name) {
            $this->UpdateFormField($name, 'visible', $enabled);
        }
    }

    public function GetConsumerForm(string $unit, bool $condition): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($form['elements'] as $element) {
            if (($element['name'] ?? '') === 'Consumers') {
                $fields = $element['form'];
                foreach ($fields as &$field) {
                    $name = $field['name'];
                    if ($name === 'LastUnit') {
                        $field['value'] = $unit;
                    }
                    if (in_array($name, ['Condition', 'ConditionHint', 'StopOnCondition'], true)) {
                        $field['visible'] = $condition;
                    }
                    if ($name === 'Unit') {
                        $field['options'][0]['caption'] = $this->ReadPropertyString('PowerUnit');
                    }
                    $field['caption'] = $this->consumerCaption($name, $unit, $field['caption']);
                    if (in_array($name, ['MaxPower', 'Voltage', 'Phases', 'PerPhaseW', 'ZeroToOne', 'Min', 'Step'], true)) {
                        $field['visible'] = $this->consumerFieldVisible($name, $unit);
                    }
                }
                unset($field);
                return json_encode($fields, JSON_THROW_ON_ERROR);
            }
        }
        return '[]';
    }

    private function consumerCaption(string $name, string $unit, string $fallback): string
    {
        $powerUnit = $this->ReadPropertyString('PowerUnit');
        $selected = $unit === 'A' ? 'A' : ($unit === 'Scale' ? 'Skalenwert' : $powerUnit);
        $captions = ['Max' => 'Maximum (' . $selected . ')', 'Min' => 'Minimaler aktiver Sollwert (' . $selected . ')',
            'Step' => 'Schrittweite (' . $selected . ')', 'MaxPower' => 'Maximaler Verbrauch (' . $powerUnit . ')'];
        return $captions[$name] ?? str_replace('{unit}', $powerUnit, $fallback);
    }

    private function consumerFieldVisible(string $name, string $unit): bool
    {
        if ($name === 'MaxPower') {
            return $unit === 'Scale';
        }
        if ($name === 'ZeroToOne') {
            return $unit === 'A';
        }
        if ($name === 'PerPhaseW') {
            return $unit === 'W';
        }
        if (in_array($name, ['Voltage', 'Phases'], true)) {
            return in_array($unit, ['A', 'W'], true);
        }
        return $unit !== 'Switch';
    }

    public function ConfigureLoadUnit(string $unit, string $previous, float $max, float $min, float $step, float $maxPower, float $voltage, int $phases, bool $perPhase): void
    {
        if (!in_array($unit, ['W', 'A', 'Switch', 'Scale'], true)) {
            throw new RuntimeException('Ungültige Verbrauchereinheit');
        }
        if ($unit !== $previous) {
            $old = ['Unit' => $previous, 'Max' => $max, 'MaxPower' => $maxPower, 'Voltage' => $voltage, 'Phases' => $phases, 'PerPhaseW' => $perPhase, 'PowerFactor' => $this->powerFactor()];
            $new = array_replace($old, ['Unit' => $unit]);
            if (in_array($previous, ['W', 'A'], true) && in_array($unit, ['W', 'A'], true) && $voltage > 0 && $phases > 0) {
                // Die gleiche physische Leistungsgrenze bleibt beim Einheitenwechsel erhalten.
                $ratio = PeakPlanner::factor($old) / PeakPlanner::factor($new);
                $this->UpdateFormField('Max', 'value', round($max * $ratio, 2));
                $this->UpdateFormField('Min', 'value', round($min * $ratio, 2));
                $this->UpdateFormField('Step', 'value', $unit === 'A' ? 1 : max(0.01, round($step * $ratio, 2)));
            }
            $this->UpdateFormField('LastUnit', 'value', $unit);
        }
        foreach (['Max', 'Min', 'Step', 'MaxPower'] as $name) {
            $this->UpdateFormField($name, 'caption', $this->consumerCaption($name, $unit, $name));
        }
        foreach (['MaxPower', 'Voltage', 'Phases', 'PerPhaseW', 'ZeroToOne', 'Min', 'Step'] as $name) {
            $this->UpdateFormField($name, 'visible', $this->consumerFieldVisible($name, $unit));
        }
    }

    public function ConfigureUpdateMode(string $mode): void
    {
        $this->UpdateFormField('Interval', 'visible', $mode === 'timer');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // VM_UPDATE meldet auch identische Updates. Nur ein geänderter Netz-Wert löst aus.
        if ($Message === VM_UPDATE && $SenderID === $this->ReadPropertyInteger('GridPower')
            && $this->ReadPropertyString('UpdateMode') === 'change'
            && $this->ReadPropertyBoolean('Enabled') && !empty($Data[1])) {
            $this->Control();
        }
    }

    private function trace(string $event, array $details = []): void
    {
        $trace = json_decode($this->ReadAttributeString('Trace'), true) ?: [];
        $trace[] = ['Zeit UTC' => gmdate('c'), 'Ereignis' => $event, 'Details' => $details];
        $this->WriteAttributeString('Trace', json_encode(array_slice($trace, -40), JSON_THROW_ON_ERROR));
    }

    public function GetDiagnostics(): string
    {
        $settings = [];
        foreach (['Enabled', 'InvertGrid', 'InvertBattery', 'InvertBatteryCommand', 'InvertCharge', 'InvertDischarge', 'PeakOverridesHold', 'BatteryBuffer'] as $name) {
            $settings[$name] = $this->ReadPropertyBoolean($name);
        }
        foreach (['PowerUnit', 'BatteryMode', 'Distribution', 'UpdateMode'] as $name) {
            $settings[$name] = $this->ReadPropertyString($name);
        }
        foreach (['Target', 'Hysteresis', 'MaxCharge', 'MaxDischarge', 'MinSOC', 'MaxSOC'] as $name) {
            $settings[$name] = $this->ReadPropertyFloat($name);
        }
        foreach (['Interval', 'MaxAge'] as $name) {
            $settings[$name] = $this->ReadPropertyInteger($name);
        }
        $variables = [];
        foreach (['GridPower', 'BatteryActual', 'BatterySetpoint', 'BatteryCharge', 'BatteryDischarge', 'SOC'] as $name) {
            $variables[$name] = $this->diagnosticVariable($this->ReadPropertyInteger($name));
        }
        $loads = [];
        $overrides = json_decode($this->ReadAttributeString('LoadOverrides'), true) ?: [];
        $configuredLoads = json_decode($this->ReadPropertyString('Consumers'), true);
        foreach (is_array($configuredLoads) ? $configuredLoads : [] as $load) {
            // Nur bekannte Steuerparameter aufnehmen; keine fremden Stringwerte/Umgebungsdaten.
            $entry = array_intersect_key($load, array_flip(['Name', 'Unit', 'Priority', 'Mode', 'Min', 'Max', 'Step', 'MaxPower', 'Voltage', 'Phases', 'PerPhaseW', 'ZeroToOne', 'MinRuntime', 'FollowUp', 'Delay', 'UseCondition', 'Condition']));
            $entry['Laufzeitänderungen'] = $overrides[(string) ($load['Setpoint'] ?? 0)] ?? [];
            $entry['Aktionsvariable'] = $this->diagnosticVariable((int) ($load['Setpoint'] ?? 0));
            $entry['Istleistung'] = $this->diagnosticVariable((int) ($load['ActualPower'] ?? 0));
            $loads[] = $entry;
        }
        return json_encode(['Modulversion' => '0.5.1', 'Instanz' => $this->InstanceID, 'Erstellt UTC' => gmdate('c'),
            'Letzte Regelung UTC' => $this->ReadAttributeString('LastRun'), 'Letzter Fehler' => $this->ReadAttributeString('LastError'),
            'Regelstatus' => $this->GetValue('State'), 'Einstellungen' => $settings, 'Variablen' => $variables,
            'Verbraucher' => $loads, 'Netzvariable abonniert' => (int) $this->ReadAttributeString('SubscribedGrid'),
            'Letzte Ereignisse' => json_decode($this->ReadAttributeString('Trace'), true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function diagnosticVariable(int $id): array
    {
        if (!$id || !IPS_VariableExists($id)) {
            return ['ID' => $id, 'Zustand' => $id ? 'Nicht vorhanden' : 'Nicht ausgewählt'];
        }
        $meta = IPS_GetVariable($id);
        $numeric = in_array($meta['VariableType'], [0, 1, 2], true);
        $value = $numeric ? GetValue($id) : 'Kein numerischer Wert';
        if (is_float($value) && !is_finite($value)) {
            $value = 'Ungültiger numerischer Wert (NaN/Unendlich)';
        }
        return ['ID' => $id, 'Typ' => $meta['VariableType'], 'Wert' => $value,
            'Alter Sekunden' => max(0, time() - $meta['VariableUpdated']),
            'Aktion vorhanden' => (bool) ($meta['VariableCustomAction'] ?: $meta['VariableAction'])];
    }

    public function GenerateDiagnostics(): string
    {
        // Diagnose liest nur; sie schaltet keine Geräte und startet keinen Regelzyklus.
        $report = $this->GetDiagnostics();
        $this->SetValue('Diagnostics', $report);
        return $report;
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
            IPS_SetVariableProfileDigits($name, 2);
            IPS_SetVariableProfileValues($name, 0, 0, 0);
        }
        IPS_SetVariableProfileDigits($name, 2);
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
            if ($name === 'Interval') {
                $element['visible'] = $this->ReadPropertyString('UpdateMode') === 'timer';
            }
            if ($name === 'Consumers') {
                $element = $this->loadFormUnit($element, $unit);
                $overrides = json_decode($this->ReadAttributeString('LoadOverrides'), true);
                $element['form'] = 'return json_decode(PS_GetConsumerForm($id, $Consumers["Unit"] ?? "W", $Consumers["UseCondition"] ?? false), true);';
                $element['loadValuesFromConfiguration'] = false;
                $element['values'] = array_map(static fn(array $load): array => array_replace($load, $overrides[(string) ($load['Setpoint'] ?? 0)] ?? [], ['LastUnit' => $load['Unit'] ?? 'W']), json_decode($this->ReadPropertyString('Consumers'), true));
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
        $overrides = json_decode($this->ReadAttributeString('LoadOverrides'), true);
        foreach ($loads as $load) {
            $load = array_replace($load, $overrides[(string) ($load['Setpoint'] ?? 0)] ?? []);
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
            $this->EnableAction('LoadMode_' . $id);
            $this->RegisterVariableInteger('LoadPriority_' . $id, $name . ': ' . $this->Translate('Reihenfolge'), '', 104);
            $this->EnableAction('LoadPriority_' . $id);
            $this->SetValue('LoadMode_' . $id, $this->modeCaption($load['Mode'] ?? 'Auto'));
            $this->SetValue('LoadPriority_' . $id, (int) ($load['Priority'] ?? 1));
        }
        $previous = json_decode($this->ReadAttributeString('LoadIDs') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        foreach (array_diff($previous, $current) as $id) {
            foreach (['LoadActive_', 'LoadPower_', 'LoadState_', 'LoadMode_', 'LoadPriority_'] as $prefix) {
                $this->UnregisterVariable($prefix . $id);
            }
        }
        $overrides = array_intersect_key($overrides, array_flip(array_map('strval', $current)));
        $this->WriteAttributeString('LoadOverrides', json_encode($overrides, JSON_THROW_ON_ERROR));
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
            $this->EnableAction('LoadPriority_' . $id);
            $this->SetValue('LoadMode_' . $id, $this->modeCaption($load['Mode']));
            $this->SetValue('LoadPriority_' . $id, (int) $load['Priority']);
            $state = !$load['allowed'] ? ($load['ConditionHint'] ?: 'Durch Bedingung gesperrt') : ($active ? 'Aktiv' : 'Inaktiv');
            $this->SetValue('LoadState_' . $id, $this->Translate($state));
        }
    }

    private function conditionPassing($condition): bool
    {
        $json = is_string($condition) ? $condition : json_encode($condition, JSON_THROW_ON_ERROR);
        if (trim($json) === '') {
            return true;
        }
        $nodes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!$nodes) {
            return true;
        }
        $roots = isset($nodes['rules']) ? [$nodes] : $nodes;
        $empty = true;
        foreach ($roots as $node) {
            if (!is_array($node) || !isset($node['rules'])) {
                $empty = false;
                break;
            }
            foreach ($node['rules'] as $rules) {
                if (!empty($rules)) {
                    $empty = false;
                }
            }
        }
        return $empty || IPS_IsConditionPassing($json);
    }

    public function GetVisualizationTile()
    {
        $data = json_encode($this->visualizationData(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        return str_replace('__INITIAL_DATA__', $data, file_get_contents(__DIR__ . '/module.html'));
    }

    private function visualizationData(): array
    {
        $loads = [];
        $overrides = json_decode($this->ReadAttributeString('LoadOverrides'), true);
        foreach (json_decode($this->ReadPropertyString('Consumers'), true) as $load) {
            $id = (int) ($load['Setpoint'] ?? 0);
            if (!$id) {
                continue;
            }
            $load = array_replace($load, $overrides[(string) $id] ?? []);
            $loads[] = ['id' => $id, 'name' => ($load['Name'] ?? '') ?: 'Verbraucher ' . $id,
                'mode' => $load['Mode'] ?? 'Auto', 'priority' => (int) ($load['Priority'] ?? 1),
                'power' => $this->GetValue('LoadPower_' . $id), 'state' => $this->GetValue('LoadState_' . $id)];
        }
        usort($loads, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        $battery = null;
        $combined = $this->ReadPropertyString('BatteryMode') === 'signed';
        $batteryID = $this->ReadPropertyInteger($combined ? 'BatterySetpoint' : 'BatteryCharge');
        $dischargeID = $this->ReadPropertyInteger('BatteryDischarge');
        if ($batteryID || (!$combined && $dischargeID)) {
            $actualID = $this->ReadPropertyInteger('BatteryActual');
            if ($actualID && IPS_VariableExists($actualID)) {
                $power = ($this->ReadPropertyBoolean('InvertBattery') ? -1 : 1) * (float) GetValue($actualID);
            } elseif ($combined && IPS_VariableExists($batteryID)) {
                $power = ($this->ReadPropertyBoolean('InvertBatteryCommand') ? -1 : 1) * (float) GetValue($batteryID);
            } else {
                $power = ($dischargeID && IPS_VariableExists($dischargeID) ? ($this->ReadPropertyBoolean('InvertDischarge') ? -1 : 1) * (float) GetValue($dischargeID) : 0)
                    - ($batteryID && IPS_VariableExists($batteryID) ? ($this->ReadPropertyBoolean('InvertCharge') ? -1 : 1) * (float) GetValue($batteryID) : 0);
            }
            $battery = ['power' => is_finite($power) ? abs($power) : null, 'state' => !is_finite($power) ? 'Ungültiger Messwert' : ($power > 0 ? 'Entladen' : ($power < 0 ? 'Laden' : 'Passiv'))];
        }
        return ['battery' => $battery, 'grid' => $this->GetValue('Grid'), 'target' => $this->ReadPropertyFloat('Target'),
            'active' => $this->GetValue('PeakActive'), 'state' => $this->GetValue('State'),
            'enabled' => $this->ReadPropertyBoolean('Enabled'), 'unit' => $this->ReadPropertyString('PowerUnit'),
            'distribution' => $this->ReadPropertyString('Distribution'), 'loads' => $loads];
    }

    private function publishVisualization(): void
    {
        if (method_exists($this, 'UpdateVisualizationValue')) {
            $this->UpdateVisualizationValue(json_encode($this->visualizationData(), JSON_THROW_ON_ERROR));
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $loads = json_decode($this->ReadPropertyString('Consumers'), true, 512, JSON_THROW_ON_ERROR);
        $ids = array_map(static fn(array $load): int => (int) $load['Setpoint'], $loads);
        $overrides = json_decode($this->ReadAttributeString('LoadOverrides'), true, 512, JSON_THROW_ON_ERROR);
        if ($Ident === 'Reorder') {
            $order = json_decode((string) $Value, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($order) || count($order) !== count($ids) || count(array_unique($order)) !== count($ids) || array_diff($ids, $order)) {
                throw new RuntimeException('Ungültige Verbraucherreihenfolge');
            }
            foreach ($order as $position => $id) {
                $overrides[(string) $id]['Priority'] = $position + 1;
                $this->SetValue('LoadPriority_' . $id, $position + 1);
            }
        } elseif (preg_match('/^Load(Mode|Priority)_(\d+)$/', $Ident, $match)) {
            $id = (int) $match[2];
            if (!in_array($id, $ids, true)) {
                throw new RuntimeException('Verbraucher nicht vorhanden');
            }
            if ($match[1] === 'Mode') {
                $Value = ['Automatisch' => 'Auto', 'Aktiv' => 'On', 'Inaktiv' => 'Off'][$Value] ?? $Value;
                if (!in_array($Value, ['Auto', 'On', 'Off'], true)) {
                    throw new RuntimeException('Ungültiger Verbrauchermodus');
                }
                $overrides[(string) $id]['Mode'] = $Value;
                $this->SetValue($Ident, $this->modeCaption($Value));
            } else {
                if (!is_numeric($Value) || (int) $Value < 1 || (float) $Value != (int) $Value) {
                    throw new RuntimeException('Priorität muss eine positive Ganzzahl sein');
                }
                $overrides[(string) $id]['Priority'] = (int) $Value;
                $this->SetValue($Ident, (int) $Value);
            }
        } else {
            throw new RuntimeException('Unbekannte Aktion');
        }
        $this->WriteAttributeString('LoadOverrides', json_encode($overrides, JSON_THROW_ON_ERROR));
        $this->publishVisualization();
    }

    private function readMeasurement(int $id, string $name = 'Leistung', ?int $maxAge = null): float
    {
        if (!$id || !IPS_VariableExists($id)) {
            throw new RuntimeException('Messvariable für ' . $name . ' nicht ausgewählt oder nicht vorhanden (ID ' . $id . ')');
        }
        $meta = IPS_GetVariable($id);
        if (!in_array($meta['VariableType'], [1, 2], true)) {
            throw new RuntimeException('Messvariable muss numerisch sein: ' . $id);
        }
        if (($maxAge ?? $this->ReadPropertyInteger('MaxAge')) > 0 && time() - $meta['VariableUpdated'] > ($maxAge ?? $this->ReadPropertyInteger('MaxAge'))) {
            throw new RuntimeException('Messwert für ' . $name . ' ist veraltet (ID ' . $id . ', Alter ' . (time() - $meta['VariableUpdated']) . ' s)');
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
            $this->trace('Sollwert schreiben', ['Variable' => $id, 'Sollwert' => $typed]);
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
            $this->WriteAttributeString('LastRun', gmdate('c'));
            $this->trace('Regelung gestartet');
            $this->powerFactor();
            if ($this->ReadPropertyFloat('Hysteresis') < 0 || $this->ReadPropertyInteger('MaxAge') < 1 || $this->ReadPropertyFloat('MaxCharge') < 0 || $this->ReadPropertyFloat('MaxDischarge') < 0) {
                throw new RuntimeException('Grenzen, Messwertalter oder Hysterese ungültig');
            }
            $grid = ($this->ReadPropertyBoolean('InvertGrid') ? -1 : 1) * $this->readMeasurement($this->ReadPropertyInteger('GridPower'), 'Netzleistung');
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
                $commandSign = $this->ReadPropertyBoolean('InvertBatteryCommand') ? -1 : 1;
                $chargeSign = $this->ReadPropertyBoolean('InvertCharge') ? -1 : 1;
                $dischargeSign = $this->ReadPropertyBoolean('InvertDischarge') ? -1 : 1;
                $actualID = $this->ReadPropertyInteger('BatteryActual');
                $battery = $actualID ? $sign * $this->readMeasurement($actualID) : ($mode === 'signed' ? $commandSign * (float) GetValue($batteryID) : $dischargeSign * (float) GetValue($dischargeID) - $chargeSign * (float) GetValue($batteryID));
                $socID = $this->ReadPropertyInteger('SOC');
                $soc = $socID ? $this->readMeasurement($socID, 'Batterie-Ladezustand') : null;
                $min = $this->ReadPropertyFloat('MinSOC');
                $max = $this->ReadPropertyFloat('MaxSOC');
                if ($min < 0 || $max > 100 || $min >= $max || ($soc !== null && ($soc < 0 || $soc > 100))) {
                    throw new RuntimeException('SOC oder SOC-Grenzen ungültig');
                }
                $charge = ($soc === null || $soc < $max) ? $this->ReadPropertyFloat('MaxCharge') : 0;
                $discharge = ($soc === null || $soc > $min) ? $this->ReadPropertyFloat('MaxDischarge') : 0;
            }
            $consumers = json_decode($this->ReadPropertyString('Consumers'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($consumers)) {
                throw new RuntimeException('Verbraucherliste ungültig');
            }
            foreach ($consumers as &$c) {
                $c = array_replace(['Name' => '', 'Setpoint' => 0, 'ActualPower' => 0, 'Unit' => 'W', 'Priority' => 1, 'Max' => 11000 * $this->powerFactor(), 'Min' => 0, 'Step' => 100 * $this->powerFactor(), 'Voltage' => 230, 'Phases' => 3, 'ZeroToOne' => false, 'MaxPower' => 5000 * $this->powerFactor(), 'PerPhaseW' => false, 'UseCondition' => false, 'Condition' => '[]', 'ConditionHint' => '', 'MinRuntime' => 0, 'FollowUp' => 0, 'Delay' => 0, 'StopOnCondition' => true, 'Mode' => 'Auto', 'PowerFactor' => $this->powerFactor()], $c);
                $this->checkAction((int) $c['Setpoint'], $c['Unit'] === 'Switch');
                if (in_array($c['Setpoint'], $ids)) {
                    throw new RuntimeException('Aktionsvariable mehrfach zugeordnet');
                }
                $ids[] = $c['Setpoint'];
                if (!in_array($c['Unit'], ['A', 'W', 'Switch', 'Scale'], true) || $c['MaxPower'] <= 0 || $c['MinRuntime'] < 0 || $c['FollowUp'] < 0 || $c['Delay'] < 0 || !in_array($c['Mode'], ['Auto', 'On', 'Off'], true) || $c['Step'] <= 0 || $c['Min'] < 0 || $c['Max'] <= 0 || $c['Max'] < $c['Min'] || (($c['Unit'] === 'A' || $c['PerPhaseW']) && ($c['Voltage'] <= 0 || !in_array($c['Phases'], [1, 2, 3])))) {
                    throw new RuntimeException('Verbrauchergrenzen / Einheit ungültig');
                }
                $overrides = json_decode($this->ReadAttributeString('LoadOverrides'), true, 512, JSON_THROW_ON_ERROR);
                $c = array_replace($c, $overrides[(string) $c['Setpoint']] ?? []);
                if ($c['Unit'] === 'A' && ($c['Max'] > 1000 || $c['Step'] > $c['Max'])) {
                    throw new RuntimeException(($c['Name'] ?: 'Wallbox') . ': Ampere-Modus erfordert Grenzen in A (z.B. Maximum 16, Minimum 6, Schrittweite 1), nicht Watt');
                }
                $c['commanded'] = (float) GetValue((int) $c['Setpoint']);
                // Wallboxen melden oft nur geänderte Leistung. Ein unveränderter
                // Nullwert ist gültig und wird nicht anhand seines Zeitstempels verworfen.
                $c['actual'] = $c['ActualPower'] ? $this->readMeasurement((int) $c['ActualPower'], ($c['Name'] ?: 'Verbraucher') . ': Istleistung', 0) : ($c['Unit'] === 'Switch' ? ($c['commanded'] ? $c['Max'] : 0) : ($c['Unit'] === 'A' && $c['ZeroToOne'] && $c['commanded'] <= 1 ? 0 : $c['commanded'] * PeakPlanner::factor($c)));
                // Nur ausdrücklich aktivierte Bedingungen prüfen. Ohne Haken keine Variable nötig.
                $c['allowed'] = !$c['UseCondition'] || $this->conditionPassing($c['Condition']);
                if ($c['actual'] < 0) {
                    throw new RuntimeException('Verbraucherleistung muss positiv sein');
                }
            }
            unset($c);
            $automatic = array_filter($consumers, static fn(array $c): bool => $c['Mode'] === 'Auto' && $c['allowed']);
            $plan = PeakPlanner::plan($grid, $this->ReadPropertyFloat('Target'), $this->ReadPropertyFloat('Hysteresis'), $battery, $charge, $discharge, $automatic, $this->ReadPropertyString('Distribution'), $this->ReadPropertyBoolean('BatteryBuffer'));
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
            if ($plan['battery'] !== null) {
                // Laufzeitbindungen, Bedingungen und manuelle Modi können den Plan
                // ändern. Den Batteriepuffer anhand der endgültigen Befehle nachführen.
                $difference = $grid - $this->ReadPropertyFloat('Target');
                $needed = $battery + (abs($difference) <= $this->ReadPropertyFloat('Hysteresis') ? 0 : $difference);
                foreach ($plan['consumers'] as $key => $value) {
                    $load = $consumers[$key];
                    $needed += ($load['Unit'] === 'Switch' ? ($value ? $load['Max'] : 0) : $value * PeakPlanner::factor($load)) - $load['actual'];
                }
                $plan['battery'] = max(-$charge, min($discharge, $needed));
            }
            // SOC-Grenzen gelten auch im Totbereich. Eine gemeinsame Variable
            // benötigt keinen Moduswechsel: Das konfigurierbare Vorzeichen bestimmt die Richtung.
            if ($hasBattery) {
                $commandSign = $this->ReadPropertyBoolean('InvertBatteryCommand') ? -1 : 1;
                $chargeSign = $this->ReadPropertyBoolean('InvertCharge') ? -1 : 1;
                $dischargeSign = $this->ReadPropertyBoolean('InvertDischarge') ? -1 : 1;
                $commanded = $mode === 'signed' ? $commandSign * (float) GetValue($batteryID) : $dischargeSign * (float) GetValue($dischargeID) - $chargeSign * (float) GetValue($batteryID);
                $setpoint = $plan['battery'] ?? max(-$charge, min($discharge, $commanded));
                // Ganzzahlige Watt-Aktionsvariablen auf deren reale Auflösung
                // runden; Float-Istwerte dürfen trotzdem in die Regelung eingehen.
                $outputID = $mode === 'signed' || $setpoint < 0 ? $batteryID : $dischargeID;
                if ($this->ReadPropertyString('PowerUnit') === 'W' && IPS_GetVariable($outputID)['VariableType'] === 1) {
                    $setpoint = max(-$charge, min($discharge, round($setpoint)));
                }
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
            $this->WriteAttributeString('LastError', '');
            $this->trace('Regelung abgeschlossen', ['Netzbezug' => $grid, 'Geplanter Netzbezug' => $projected, 'Einheit' => $this->ReadPropertyString('PowerUnit')]);
            $this->SetStatus(102);
            $this->publishVisualization();
        } catch (Throwable $e) {
            $this->SetValue('PeakActive', false);
            $this->SetValue('State', $e->getMessage());
$this->WriteAttributeString('LastError', $e->getMessage());
            $this->trace('Fehler', ['Meldung' => $e->getMessage()]);
            $this->SetStatus(200);
            $this->SendDebug('Regelung gestoppt', $e->getMessage(), 0);
            $this->publishVisualization();
        } finally {
            IPS_SemaphoreLeave('PeakShaving_' . $this->InstanceID);
        }
    }
}
