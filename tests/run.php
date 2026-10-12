<?php

declare(strict_types=1);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
require_once __DIR__ . '/../PeakShaving/Controller.php';
$count = 0;
function expect($actual, $expected, string $name): void
{
    global $count;
    $count++;
    if ($actual != $expected) {
        throw new RuntimeException($name . ': ' . json_encode($actual) . ' != ' . json_encode($expected));
    }
}
function consumer(array $overrides = []): array
{
    return array_replace(['Priority' => 1, 'Unit' => 'W', 'Voltage' => 230, 'Phases' => 3, 'Min' => 0, 'Max' => 5000, 'Step' => 100, 'actual' => 3000], $overrides);
}
expect(PeakPlanner::plan(5090, 5000, 100, 0, 3000, 3000, []), ['battery' => null, 'consumers' => []], 'deadband');
expect(PeakPlanner::plan(6500, 5000, 100, 500, 3000, 3000, [])['battery'], 2000, 'discharge includes existing output');
expect(PeakPlanner::plan(3000, 5000, 100, 0, 3000, 3000, [])['battery'], -2000, 'charge below target');
expect(PeakPlanner::plan(7000, 5000, 100, -1000, 3000, 3000, [])['battery'], 1000, 'stop charging then discharge');
$p = PeakPlanner::plan(9000, 5000, 100, 0, 1000, 1000, [consumer(), consumer(['Priority' => 2])]);
expect($p['battery'], 1000, 'battery saturation');
expect($p['consumers'], [1 => 0], 'lowest priority sheds first');
$p = PeakPlanner::plan(4000, 5000, 100, 0, 0, 0, [consumer(['actual' => 0]), consumer(['Priority' => 2, 'actual' => 0])]);
expect($p['consumers'], [0 => 1000], 'highest priority restores first');
$p = PeakPlanner::plan(7000, 5000, 100, 0, 0, 0, [consumer(['Unit' => 'A', 'Max' => 16, 'Min' => 6, 'Step' => 1, 'actual' => 6900])]);
expect($p['consumers'], [0 => 7], 'three phase conversion');
$p = PeakPlanner::plan(6000, 5000, 100, 0, 0, 0, [consumer(['Unit' => 'A', 'Max' => 16, 'Min' => 6, 'Step' => 1, 'actual' => 4140])]);
expect($p['consumers'], [0 => 0], 'minimum charging current');
$p = PeakPlanner::plan(4500, 5000, 100, 0, 0, 0, [consumer(['Unit' => 'A', 'Max' => 16, 'Min' => 6, 'Step' => 1, 'actual' => 0])]);
expect($p['consumers'], [0 => 0], 'do not start below minimum budget');

// Minimal Symcon simulation exercises module wiring and action writes.
$GLOBALS['vars'] = [];
$GLOBALS['writes'] = [];
class IPSModule
{
    public int $InstanceID = 42;
    public array $properties = [];
    public array $values = [];
    public array $buffers = [];
    public array $attributes = [];
    public array $registered = [];
    public array $formUpdates = [];
    public function RegisterAttributeString($n, $v) { if (!isset($this->attributes[$n])) $this->attributes[$n] = $v; }
    public function ReadAttributeString($n) { return $this->attributes[$n]; }
    public function WriteAttributeString($n, $v) { $this->attributes[$n] = $v; }
    public function Translate($s) { return $s; }
    public function UpdateFormField($n, $f, $v) { $this->formUpdates[$n][$f] = $v; }
    public function UnregisterVariable($n) { unset($this->registered[$n], $this->values[$n]); }
    public function GetBuffer($n) { return $this->buffers[$n] ?? ''; }
    public function SetBuffer($n, $v) { $this->buffers[$n] = $v; }
    public int $status = 0;
    public int $timer = 0;
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyBoolean($n, $v) { $this->properties[$n] = $v; }
    public function RegisterPropertyInteger($n, $v) { $this->properties[$n] = $v; }
    public function RegisterPropertyFloat($n, $v) { $this->properties[$n] = $v; }
    public function RegisterPropertyString($n, $v) { $this->properties[$n] = $v; }
    public function ReadPropertyBoolean($n) { return $this->properties[$n]; }
    public function ReadPropertyInteger($n) { return $this->properties[$n]; }
    public function ReadPropertyFloat($n) { return $this->properties[$n]; }
    public function ReadPropertyString($n) { return $this->properties[$n]; }
    public function RegisterVariableFloat($n, $caption, $profile, $position) { $this->registered[$n] = $profile; }
    public function RegisterVariableString($n, $caption, $profile, $position) { $this->registered[$n] = $profile; }
    public function RegisterVariableBoolean($n, $caption, $profile, $position) { $this->registered[$n] = $profile; }
    public function RegisterVariableInteger($n, $caption, $profile, $position) { $this->registered[$n] = $profile; }
    public function RegisterTimer(...$args) {}
    public function SetTimerInterval($n, $v) { $this->timer = $v; }
    public function SetStatus($v) { $this->status = $v; }
    public function GetValue($n) { return $this->values[$n] ?? 0; }
    public function SetValue($n, $v) { $this->values[$n] = $v; }
    public function SendDebug(...$args) {}
}
function IPS_VariableProfileExists($name) { return isset($GLOBALS['profiles'][$name]); }
function IPS_CreateVariableProfile($name, $type) { $GLOBALS['profiles'][$name] = ['type' => $type]; }
function IPS_SetVariableProfileText($name, $prefix, $suffix) { $GLOBALS['profiles'][$name]['suffix'] = $suffix; }
function IPS_SetVariableProfileDigits($name, $digits) { $GLOBALS['profiles'][$name]['digits'] = $digits; }
function IPS_SetVariableProfileValues(...$args) {}
function IPS_VariableExists($id) { return isset($GLOBALS['vars'][$id]); }
function IPS_GetVariable($id) { return $GLOBALS['vars'][$id]; }
function GetValue($id) { return $GLOBALS['vars'][$id]['value']; }
function RequestAction($id, $v) { if (($GLOBALS['failAction'] ?? 0) === $id) return false; $GLOBALS['writes'][$id] = $v; $GLOBALS['vars'][$id]['value'] = $v; return true; }
function IPS_IsConditionPassing($condition) { return $condition !== '[false]'; }
function IPS_SemaphoreEnter(...$args) { return true; }
function IPS_SemaphoreLeave(...$args) {}
function variable($id, $value, $action = 0, $age = 0, $type = 2): void
{
    $GLOBALS['vars'][$id] = ['value' => $value, 'VariableType' => $type, 'VariableUpdated' => time() - $age, 'VariableCustomAction' => 0, 'VariableAction' => $action];
}
require_once __DIR__ . '/../PeakShaving/module.php';
$m = new PeakShaving();
$m->Create();
$m->ApplyChanges();
expect($m->timer, 0, 'disabled by default');
$m->Control();
expect($GLOBALS['writes'], [], 'disabled produces no commands');
$m->properties['Enabled'] = true;
$m->properties['BatteryMode'] = 'signed';
$m->properties['GridPower'] = 1;
variable(1, 7000);
variable(2, 0, 10, 0, 1);
variable(3, 0);
variable(4, 50);
$m->properties['BatterySetpoint'] = 2;
$m->properties['BatteryActual'] = 3;
$m->properties['SOC'] = 4;
$m->ApplyChanges();
expect($m->timer, 5000, 'timer enabled');
$m->Control();
expect($GLOBALS['writes'][2], 2000, 'battery action');
expect($m->status, 102, 'module success');
$GLOBALS['writes'] = [];
variable(1, 5000);
variable(3, 800);
$m->Control();
expect($GLOBALS['writes'], [], 'deadband does not reset setpoint to measured output');
variable(4, 20);
variable(3, 0);
$m->Control();
expect($GLOBALS['writes'][2], 0, 'SOC safety inside deadband');
variable(2, -2000, 10);
variable(4, 90);
$m->Control();
expect($GLOBALS['writes'][2], 0, 'max SOC stops commanded charge before measured response');
$GLOBALS['writes'] = [];
variable(1, 7000, 0, 1000);
$m->Control();
expect($m->status, 200, 'stale grid rejected');
expect($GLOBALS['writes'], [], 'stale measurement prevents commands');
variable(1, 8000);
variable(5, 6, 10, 0, 1);
variable(6, 4140);
$m->properties['BatterySetpoint'] = 0;
$c = consumer(['Setpoint' => 5, 'ActualPower' => 6, 'Unit' => 'A', 'Max' => 16, 'Min' => 6, 'Step' => 1, 'ZeroToOne' => true]);
$m->properties['Consumers'] = json_encode([$c]);
$m->Control();
expect($GLOBALS['writes'][5], 1, 'Mennekes zero mapping');
$GLOBALS['writes'] = [];
$m->properties['Consumers'] = json_encode([$c, $c]);
$m->Control();
expect($m->status, 200, 'duplicate actuator rejected');
expect($GLOBALS['writes'], [], 'validate all devices before writing');
$m->properties['Consumers'] = '[]';
$m->properties['BatterySetpoint'] = 2;
$m->properties['InvertBattery'] = true;
$m->properties['InvertBatteryCommand'] = true;
variable(3, 0);
variable(4, 50);
$m->Control();
expect($GLOBALS['writes'][2], -3000, 'inverted battery command');
$m->properties['BatteryMode'] = 'separate';
$m->properties['BatteryCharge'] = 7;
$m->properties['BatteryDischarge'] = 8;
variable(7, 1000, 10);
variable(8, 0, 10);
variable(1, 6000);
variable(3, 0);
$GLOBALS['writes'] = [];
$m->Control();
expect($GLOBALS['writes'], [7 => 0, 8 => 1000], 'separate directions stop charging first');
variable(1, 3000);
$GLOBALS['writes'] = [];
$m->properties['InvertCharge'] = true;
$m->Control();
expect($GLOBALS['writes'], [8 => 0, 7 => -2000], 'separate negative charging');
$m->properties['BatteryStatus'] = 9;
variable(9, false, 0, 0, 0);
$GLOBALS['writes'] = [];
$m->Control();
expect($m->status, 200, 'battery unavailable');
expect($GLOBALS['writes'], [], 'unavailable prevents actions');
$m->properties['BatteryReadyValue'] = 0;
$m->Control();
expect($m->status, 102, 'configurable ready status');
$p = PeakPlanner::plan(6000, 5000, 100, 0, 0, 0, [consumer(['Unit' => 'Switch', 'Max' => 3000])]);
expect($p['consumers'], [0 => 0], 'switch sheds whole load');
$p = PeakPlanner::plan(1000, 5000, 100, 0, 0, 0, [consumer(['Unit' => 'Switch', 'actual' => 0, 'Max' => 3000])]);
expect($p['consumers'], [0 => 1], 'switch restores with full budget');
$p = PeakPlanner::plan(4000, 5000, 100, 0, 0, 0, [consumer(['Unit' => 'Switch', 'actual' => 0, 'Max' => 3000])]);
expect($p['consumers'], [0 => 0], 'switch stays off with insufficient budget');
// Documented Energy Manager-style consumer options.
$p = PeakPlanner::plan(6000, 5000, 100, 0, 0, 0, [consumer(['Unit' => 'Scale', 'Max' => 100, 'MaxPower' => 5000, 'Step' => 1, 'actual' => 2500])]);
expect($p['consumers'], [0 => 30], 'percentage scaling');
expect(PeakPlanner::factor(consumer(['PerPhaseW' => true])), 3, 'watt per phase factor');
$n = new PeakShaving();
$n->Create();
$n->properties['Enabled'] = true;
$n->properties['GridPower'] = 1;
$n->properties['PeakOverridesHold'] = false;
variable(1, 7000);
variable(20, true, 10, 0, 0);
$c = consumer(['Setpoint' => 20, 'ActualPower' => 0, 'Unit' => 'Switch', 'MinRuntime' => 60]);
$n->properties['Consumers'] = json_encode([$c]);
$GLOBALS['writes'] = [];
$n->Control();
expect($GLOBALS['writes'], [], 'minimum runtime holds switch');
$n->properties['PeakOverridesHold'] = true;
$n->Control();
expect($GLOBALS['writes'][20], false, 'peak overrides minimum runtime');
variable(20, true, 10, 0, 0);
$n->properties['PeakOverridesHold'] = false;
$c['Condition'] = '[false]';
$n->properties['Consumers'] = json_encode([$c]);
$GLOBALS['writes'] = [];
variable(1, 5000);
$n->Control();
expect($GLOBALS['writes'][20], false, 'condition stops even in grid deadband');
expect(str_contains($n->values['State'], 'Bedingung'), true, 'condition status');
$c['Condition'] = '[]';
$c['MinRuntime'] = 0;
$c['FollowUp'] = 60;
$n->properties['Consumers'] = json_encode([$c]);
variable(20, true, 10, 0, 0);
variable(1, 7000);
$n->buffers = [];
$GLOBALS['writes'] = [];
$n->Control();
expect($GLOBALS['writes'], [], 'follow-up holds switch');
$n->buffers['Timing'] = json_encode([20 => ['started' => time()-120, 'deficit' => time()-120, 'changed' => 0]]);
$n->Control();
expect($GLOBALS['writes'][20], false, 'follow-up expires');
$c['FollowUp'] = 0;
$c['Delay'] = 30;
$n->properties['Consumers'] = json_encode([$c]);
variable(20, false, 10, 0, 0);
variable(1, 1000);
$n->buffers['Timing'] = json_encode([20 => ['started' => 0, 'deficit' => 0, 'changed' => time()]]);
$GLOBALS['writes'] = [];
$n->Control();
expect($GLOBALS['writes'], [], 'consumer delay');
$c['Delay'] = 0;
$c['Mode'] = 'On';
$n->properties['Consumers'] = json_encode([$c]);
variable(1, 7000);
$n->Control();
expect($GLOBALS['writes'][20], true, 'manual on independent of deficit');
$c['Mode'] = 'Off';
$n->properties['Consumers'] = json_encode([$c]);
$GLOBALS['writes'] = [];
$n->Control();
expect($GLOBALS['writes'][20], false, 'manual off');
$n->properties['Consumers'] = '[]';
$n->properties['InvertGrid'] = true;
variable(1, -5000);
$n->Control();
expect($n->values['Grid'], 5000, 'grid sign inversion');
// Optional measured battery output; legacy mode-switch properties must be ignored.
$m->properties['BatteryStatus'] = 0;
$m->properties['BatteryActual'] = 0;
$m->properties['BatteryModeVariable'] = 10;
variable(10, 1, 10, 0, 1);
variable(7, -1000, 10);
variable(8, 0, 10);
variable(1, 7000);
$GLOBALS['writes'] = [];
$m->Control();
expect($GLOBALS['writes'], [7 => 0, 8 => 1000], 'sign determines direction; legacy mode variable is never written');
variable(7, -1000, 10);
variable(8, 0, 10);
variable(10, 1, 10, 0, 1);
$GLOBALS['failAction'] = 7;
$GLOBALS['writes'] = [];
$m->Control();
expect($m->status, 200, 'failed action reported');
expect($GLOBALS['writes'], [], 'failed stop prevents mode change and opposite direction');
$GLOBALS['failAction'] = 0;
// Physically identical W and kW scenarios must produce identical outcomes.
$kw = new PeakShaving();
$kw->Create();
$kw->properties = array_replace($kw->properties, ['Enabled' => true, 'PowerUnit' => 'kW', 'GridPower' => 30, 'Target' => 5.0, 'Hysteresis' => 0.1, 'MaxDischarge' => 3.0, 'MaxCharge' => 3.0, 'BatteryMode' => 'signed', 'BatterySetpoint' => 31, 'BatteryActual' => 32, 'SOC' => 33]);
variable(30, 7.0); variable(31, 0, 10); variable(32, 0); variable(33, 50);
$kw->ApplyChanges();
$kw->Control();
expect($GLOBALS['writes'][31], 2.0, 'kW battery output');
expect($kw->values['Grid'], 7.0, 'kW grid display');
expect($kw->values['Projected'], 5.0, 'kW projected net power avoids double counting');
expect($kw->values['PeakActive'], true, 'peak status true above threshold');
expect($kw->registered['Grid'], 'PS.Power.kW', 'kW profile assignment');
expect($GLOBALS['profiles']['PS.Power.kW']['suffix'], ' kW', 'kW profile suffix');
expect($GLOBALS['profiles']['PS.Power.kW']['digits'], 3, 'kW profile precision');
variable(30, 5.05);
$kw->Control();
expect($kw->values['PeakActive'], false, 'peak status false inside deadband');
variable(30, 7, 0, 1000);
$kw->Control();
expect($kw->values['PeakActive'], false, 'peak status clears on error');
variable(30, 7);
$kw->properties['BatterySetpoint'] = 0;
variable(34, 10, 10, 0, 1);
variable(35, 6.9);
$c = consumer(['Setpoint' => 34, 'ActualPower' => 35, 'Unit' => 'A', 'Max' => 16, 'Min' => 6, 'Step' => 1, 'ZeroToOne' => false]);
$kw->properties['Consumers'] = json_encode([$c]);
$kw->ApplyChanges();
$kw->Control();
expect($GLOBALS['writes'][34], 7, 'kW conversion preserves ampere commands');
expect(round($kw->values['Projected'], 2), 4.93, 'kW consumer delta accounting');
expect($kw->values['LoadPower_34'], 6.9, 'load status power in kW');
expect($kw->values['LoadActive_34'], true, 'load command active status');
expect($kw->values['LoadPriority_34'], 1, 'load configuration priority variable');
expect($kw->registered['LoadPower_34'], 'PS.Power.kW', 'load power profile');
$kw->properties['Consumers'] = '[]';
$kw->ApplyChanges();
expect(isset($kw->registered['LoadPower_34']), false, 'removed load variables cleaned up');
$kw->properties['Enabled'] = false;
$kw->Control();
expect($kw->values['PeakActive'], false, 'disabled peak status');
$kw->properties['BatteryMode'] = 'signed';
$form = json_decode($kw->GetConfigurationForm(), true);
$elements = array_column($form['elements'], null, 'name');
expect($elements['BatterySetpoint']['visible'], true, 'combined variable visible');
expect($elements['BatteryCharge']['visible'], false, 'charge variable hidden in combined mode');
expect($elements['BatteryDischarge']['visible'], false, 'discharge variable hidden in combined mode');
expect(str_contains($elements['Target']['caption'], 'kW'), true, 'form unit labels');
expect(isset($elements['BatteryModeVariable']), false, 'no mode-switch selector');
$kw->ConfigureForm('separate', 'W');
expect($kw->formUpdates['BatterySetpoint']['visible'], false, 'live form hides combined variable');
expect($kw->formUpdates['BatteryCharge']['visible'], true, 'live form shows separate variable');
expect($kw->formUpdates['Target']['caption'], 'Zielnetzbezug (W)', 'live form unit change');
$kw->properties['Enabled'] = true;
$kw->properties['BatteryMode'] = 'signed';
$kw->properties['BatterySetpoint'] = 31;
variable(30, 6.001);
variable(31, 0, 10);
$kw->Control();
expect(round($GLOBALS['writes'][31], 6), 1.001, 'small kW output not lost to rounding tolerance');
// Fractional kW cannot be represented by integer action variables.
variable(31, 0, 10, 0, 1);
$GLOBALS['writes'] = [];
$kw->Control();
expect($kw->status, 200, 'integer kW actuator rejected instead of silently rounded');
expect($GLOBALS['writes'], [], 'no inaccurate integer command sent');
$kw->properties['PowerUnit'] = 'W';
$kw->values['Grid'] = 7.0;
$kw->values['Projected'] = 5.0;
$kw->ApplyChanges();
expect($kw->values['Grid'], 7000, 'existing grid status scales on unit switch');
expect($kw->values['Projected'], 5000, 'existing projected status scales on unit switch');
expect($kw->registered['Grid'], 'PS.Power.W', 'W profile restored');
$kw->ApplyChanges();
expect($kw->values['Grid'], 7000, 'repeat ApplyChanges does not double scale');
$kw->properties['PowerUnit'] = 'invalid';
$kw->ApplyChanges();
expect($kw->status, 200, 'invalid unit reported');
expect($kw->timer, 0, 'invalid unit prevents timer startup');
echo "$count checks passed\n";
