# Peak Shaving für IP-Symcon

Eigenständiges PHP-Modul für IP-Symcon ab Version 7.1. Ziel ist das Begrenzen des Netzbezugs durch frei zugeordnete Batterie- und Verbrauchervariablen. Orientierung: [Symcon Energie Manager](https://www.symcon.de/de/llms/modules/energy-manager.md). Das Modul ist keine Kopie des Energie Managers und hat noch keinen Funktionstest in einer echten Symcon-Instanz bestanden.

## Installation

Dieses Repository über die Modulverwaltung von IP-Symcon als Git-Modul hinzufügen und eine Instanz **Peak Shaving** erstellen. Die Regelung startet deaktiviert. Unter **Globale Leistungseinheit** zwischen W (Standard) und kW wählen. Alle externen Leistungs-Mess-/Aktionsvariablen und Leistungsgrenzen müssen dieselbe Einheit verwenden. Beim Wechsel die bestehenden Grenzwerte selbst umrechnen (5.000 W → 5 kW); Ampere, skalierte Sollwerte und SOC werden nicht umgerechnet. Dezimale Leistungs-Sollwerte benötigen Float-Aktionsvariablen. Variablen zuordnen und Grenzwerte prüfen, dann aktivieren. Die Aktion **Jetzt regeln** arbeitet ebenfalls nur bei aktivierter Regelung. Dieselben Geräte sollten nicht parallel von einem weiteren Energie Manager gesteuert werden.

## Netzbezug

- **Netzleistung:** Messvariable in der ausgewählten Leistungseinheit. Standardmäßig ist Bezug positiv und Einspeisung negativ; invertierbar.
- **Zielnetzbezug:** gewünschter Bezug, beispielsweise 5.000 W oder 5 kW.
- **Hysterese:** symmetrischer Totbereich. Bei 5.000 W Ziel und 100 W Hysterese werden zwischen 4.900 und 5.100 W keine normalen Anpassungen durchgeführt.
- **Intervall:** standardmäßig 5 Sekunden, passend zur Geräteverzögerung wählen.
- **Maximales Messwertalter:** standardmäßig 60 Sekunden. Fehlende oder veraltete ausgewählte Messwerte stoppen die Regelung ohne neue Befehle; alte Sollwerte bleiben bestehen.
- **Laufzeiten übersteuern:** standardmäßig aktiv. Übersteigt der Netzbezug Ziel plus Hysterese, darf die Regelung Mindestlaufzeit, Nachlaufzeit und Verzögerungen übersteuern. Ist dies deaktiviert, können diese Bindungen den Zielnetzbezug verhindern.

## Batterie: freie Variablen statt Gerätemodell

Die Batterie ist optional. Es wird keine Geräteinstanz benötigt. Zwei Varianten sind verfügbar:

| Variante | Zuordnung |
| --- | --- |
| Getrennte Variablen (Standard) | Lade- und Entladesollwert in W bzw. kW, jeweils mit Symcon-Aktion |
| Gemeinsamer Sollwert | Vorzeichenbehafteter Leistungssollwert in W bzw. kW, mit Symcon-Aktion |

Die Vorzeichen für Laden, Entladen und den gemeinsamen Sollwert sind separat konfigurierbar. Bei gemeinsamem Sollwert gilt standardmäßig Entladen positiv, Laden negativ.

Die **Istleistung** ist optional und hat eine unabhängig invertierbare Vorzeichenkonvention (standardmäßig Entladen positiv). Ohne Istvariable wird die Leistung aus den Sollwerten geschätzt. Echte Rückmeldung ist vorzuziehen, da Sollwerte nicht zwangsläufig umgesetzt werden.

**SOC** ist eine optionale Messvariable von 0 bis 100 Prozent. Maximale Lade-/Entladeleistung und minimaler/maximaler SOC begrenzen die Befehle. Am minimalen SOC wird nicht entladen, am maximalen nicht geladen. SOC-Schutz gilt auch innerhalb der Netz-Hysterese. Ohne SOC-Variable wird keine ID 0 gelesen und es erfolgt keine SOC-Prüfung; der Speicher muss seine Ladezustandsgrenzen selbst absichern.

Eine Bereitschaftsvariable oder ein Bereitschaftswert ist nicht erforderlich. Alte Bereitschaftseinstellungen werden ignoriert.

Eine zusätzliche Umschaltvariable wird nicht mehr verwendet. Bei einem gemeinsamen Sollwert bestimmt ausschließlich das konfigurierbare Vorzeichen die Lade-/Entladerichtung; 0 ist passiv. Die getrennten Sollwertselektoren sind dann verborgen. Beim Wechsel zu getrennten Variablen werden nur diese beiden Selektoren angezeigt. Zuerst wird die Gegenrichtung auf 0 gesetzt; bei einem fehlgeschlagenen Stopp wird die neue Richtung nicht gestartet. Alte Umschalt-Einstellungen werden ignoriert.

Die Sollwertaktionen müssen die Batterie tatsächlich steuern. Zusätzliche gerätespezifische Freigaben, Kommunikationsprotokolle oder Bestätigungen werden von der Geräteanbindung bereitgestellt. Der Wechsel erfolgt nach erfolgreicher Ausführung der Symcon-Aktion; eine geräteseitige Quittierung oder Totzeit wird nicht automatisch abgewartet. Die Batterie muss ihre eigenen Schutzfunktionen behalten.

## Verbraucher und Wallboxen

Die Liste folgt den grundlegenden Möglichkeiten des Energie Managers: schaltbare und skalierbare Verbraucher, optionaler gemessener Verbrauch und Laufzeitbindungen. Mehrere Verbraucher mit freien Aktionsvariablen lassen sich hinterlegen.

| Einstellung | Bedeutung |
| --- | --- |
| Aktionsvariable | Numerischer Sollwert oder bei Ein/Aus eine boolesche Aktionsvariable |
| Aktueller Verbrauch | Optionaler Messwert in W/kW; sonst Schätzung aus Sollwert und Konfiguration |
| Einheit | Watt, Ampere, Ein/Aus oder skalierter Sollwert (beispielsweise Prozent) |
| Priorität | Kleine Werte werden zuerst versorgt; große Werte werden zuerst reduziert |
| Maximum / Minimum | Grenzen in der gewählten Sollwerteinheit; bei Ein/Aus ist Maximum die Einschaltleistung in W/kW |
| Maximaler Verbrauch bei Skalierung | Leistung in W/kW am maximalen Sollwert; lineare Skalierung ab 0 |
| Schrittweite | Beispielsweise 100 W, 1 A oder 1 Prozentpunkt |
| Spannung / Phasen | Umrechnung von Ampere nach Watt; feste Phasenzahl |
| Watt pro Phase | Watt-Sollwerte pro Phase statt Gesamtleistung |
| 0 A als 1 A schreiben | Ampere-Modus: ersetzt einen Stopp-Sollwert 0 durch 1 A |
| Mindestlaufzeit | Nach Aktivierung nicht vollständig abschalten; skalierbare Geräte können bis zur Mindestleistung reduziert werden |
| Nachlaufzeit | Bei fehlendem Budget erst nach dieser Dauer vollständig stoppen |
| Verzögerung | Pause zwischen Sollwertänderungen |
| Modus | Automatisch, Aktiv (Maximum) oder Inaktiv (Stopp) |

Im Modus **Aktiv** ist das Gerät von der automatischen Lastreduzierung ausgeschlossen und kann den Zielnetzbezug verhindern. Im Modus **Inaktiv** wird es gestoppt. Die Modusauswahl erfolgt über die Konfigurationsliste, Variablenaktionen oder die Kachel.

Bei Ein/Aus wird erst eingeschaltet, wenn die ganze konfigurierte Einschaltleistung in das Budget passt. Skalierte Sollwerte verwenden einen expliziten Bereich von 0 bis Maximum; das Modul liest dafür keine Variablenprofile aus.

Beispiel Wallbox: A, Minimum 6, Maximum 16, Schrittweite 1, Spannung 230, Phasen 3. Für Prozentsteuerung beispielsweise Maximum 100, Schrittweite 1 und maximaler Verbrauch 3.000 W. Werte immer passend zum konkreten Gerät wählen.

Die Mennekes-Option ist eine Protokollanpassung: **1 A darf nur als Stopp gelten, wenn das konkrete Gerät dies so interpretiert.** Sie garantiert keine reale Leistungsaufnahme von 0 W. Bei Schätzung wird dieser Wert als Stopp behandelt; mit Messvariable wird der reale Verbrauch verwendet.

## Regelablauf und Grenzen

1. Messwerte, Aktionsvariablen und Konfiguration prüfen.
2. Batterie zuerst anpassen: neue Entladeleistung = Ist-Entladeleistung + Netzbezug − Ziel, begrenzt durch Leistung und SOC.
3. Verbleibende Überschreitung durch Verbraucher mit niedriger Priorität reduzieren. Bei freier Leistung Verbraucher mit hoher Priorität anheben.
4. Schrittweiten, Mindestleistung, Betriebsmodus und Laufzeitbindungen berücksichtigen.
5. Aktionen ausführen, anschließend im nächsten Zyklus die Rückmeldung berücksichtigen.

Bei aktivierter Batterie-Pufferung steht die zulässige Entladeleistung zusätzlich für Verbraucher bereit. Es wird nur der für die endgültigen Verbraucherbefehle benötigte Batteriesollwert geschrieben. Laufende Batterieladung wird zugunsten von Verbrauchern reduziert; übrig bleibendes Budget kann den Speicher laden. Anders als der Energie Manager kann Peak Shaving mehrere Sollwerte in einem Zyklus verändern. Erzwungene Modus- oder Bedingungsänderungen wirken auf die Netzrückmeldung im folgenden Zyklus.

Statusvariablen zeigen Netzbezug, Regelstatus und **geplanten Netzbezug**. Beim Einheitenwechsel werden vorhandene aktuelle Statuswerte umgerechnet; historische Archivdaten werden nicht umgeschrieben. Leistungsvariablen erhalten eigene Profile `PS.Power.W` bzw. `PS.Power.kW` (jeweils zwei Nachkommastellen). **Peak Shaving aktiv** ist nach einem erfolgreichen Regelzyklus wahr, wenn der gemessene Netzbezug über Ziel plus Hysterese liegt; im Totbereich, bei Deaktivierung und bei Fehlern ist sie falsch. Sie meldet den Regelbedarf und bestätigt keine garantierte Leistungsbegrenzung.

Für jeden Verbraucher werden unter der Instanz Variablen für **Aktiv**, **Leistung**, **Status**, **Modus** und **Priorität** angelegt. Aktiv beschreibt den zuletzt angeforderten Betriebszustand (mit Mennekes-Stoppkonvention), Leistung die gemessene oder geschätzte Istleistung des letzten gültigen Zyklus. Modus und Priorität können über die Verbraucherliste, Variablenaktionen oder die eigene Visualisierung geändert werden. Laufzeitänderungen werden persistent gespeichert und im Formular angezeigt; eine geänderte Verbraucherliste übernimmt wieder die Formularwerte. Entfernte Verbraucher verlieren ihre Statusvariablen beim Übernehmen; historische Daten dieser Variablen können dabei verloren gehen.

Der geplante Netzbezug berücksichtigt ausschließlich Leistungsänderungen: `Netz-Ist − (Batterie-Soll − Batterie-Ist) + Summe(Verbraucher-Sollleistung − Verbraucher-Istleistung)`. Letzterer ist eine Prognose aus Istwerten und Befehlen, kein Messwert. Reicht die verfügbare Flexibilität nicht aus, wird dies gemeldet.

Abtastintervall, Geräteverzögerung, Laufzeitbindungen, manuelle Modi und Stromschritte können Überschreitungen verursachen. Ohne reale Leistungsmessungen sind die Berechnungen Schätzungen. Bereits gesendete Befehle werden bei Mess- oder Aktionsfehlern nicht automatisch zurückgenommen. Dies ist keine elektrische Schutzfunktion.

Nicht Bestandteil dieser Version: die eingebaute Energie-Manager-Darstellung und deren Nacht-/preisabhängiges Laden, dynamische Phasenumschaltung, Gerätevorlagen, Einschränkungen nach §14a/§9 und indirekt geregelte Batteriespeicher.

## Entwicklung

```sh
php -l PeakShaving/module.php
php -l PeakShaving/Controller.php
php tests/run.php
```

Die Tests prüfen die Leistungsberechnung und simulierte Symcon-Aktionsaufrufe einschließlich Vorzeichen, SOC, Prioritäten, Ampere-/Prozentskalierung, Mindeststrom, Mennekes-Anpassung, Bedingungen, Laufzeiten, Verzögerung, manuellen Modi, veralteten Messwerten, automatischer Vorzeichenrichtung und Aktionsfehlern. Oberfläche, Gerätetreiber und Timerverhalten müssen in einer echten Symcon-Instanz validiert werden.

In der Cloud-Umgebung liegt PHP unter `/workspace/peak-tools/php/usr/bin/php8.4`. `LD_LIBRARY_PATH=/workspace/peak-tools/php/usr/lib/x86_64-linux-gnu` setzen und den Interpreter mit `-n` aufrufen.

Dokumentationsgrundlagen: [Energie Manager](https://www.symcon.de/de/llms/modules/energy-manager.md), [PHP-SDK](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/module.md), [Konfigurationsformulare](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/configuration-forms.md) und [Variablenaktionen](https://www.symcon.de/de/llms/functions/access-variables.md).

### Version 0.2.1

Die dynamische Aktualisierung der Verbraucherspalten übergibt JSON statt eines PHP-Arrays an Symcon. Dadurch wird der Fehler „Cannot auto-convert value for parameter Value“ beim Wechsel von Einheit oder Batteriemodus vermieden. Alle vom Modul bereitgestellten Beschriftungen und Modus-Anzeigen sind deutsch, auch bei englischer Konsolensprache. Systemmeldungen von Symcon selbst folgen weiterhin dessen Spracheinstellung.


### Version 0.3: Testumgebung und Visualisierung

Leere Bedingungen (auch `{}` und leere SelectCondition-Wurzelknoten) gelten als freigegeben. Netzleistung bleibt erforderlich und meldet ihren Namen bei fehlender Zuordnung. Optionale Messwerte werden nicht mit ID 0 gelesen. Messwerte dürfen Integer oder Float sein. Lade-/Entladegrenzen werden ohne Nachkommastellen eingegeben, andere Leistungsfelder mit zwei; SOC-Felder sind auf 0–100 begrenzt.

**Verteilung auf Verbraucher** wählt Priorisierung oder gleichmäßige Gesamtleistung. Gleichmäßig bedeutet Wasserfüllung: Bei Leistungsgrenzen wird freies Budget auf die anderen Verbraucher verteilt. Unterschiedliche Phasen, Schrittweiten, Mindestleistung, Bedingungen, Laufzeiten und manuelle Modi können exakte Gleichheit verhindern. Die Batterie gleicht weiterhin zuerst die Netzabweichung aus.

In der Kachelvisualisierung die Instanz hinzufügen und ihre **individuelle Darstellung** verwenden. Die eigene HTML-Kachel orientiert sich an der dokumentierten Energie-Manager-Geräteliste: Reihenfolge verschieben, Automatisch/Aktiv/Inaktiv, Einstellungen und Live-Leistung. Prioritäten lassen sich auch über Pfeile ändern. Die Batterie zeigt Lade-/Entladerichtung und Leistung; sie wird weiterhin automatisch geregelt. Der Einstellungsdialog dient Modus/Priorität; zeit-/preisabhängige Energie-Manager-Funktionen werden nicht nachgebildet. Bei gleichmäßiger Verteilung wirkt die Reihenfolge nicht auf die Leistungszuteilung. Die Kachel ist eine eigene Implementierung, kein unverändert übernommenes Symcon-Kernlayout.

Die HTML-Darstellung wurde mit Chromium hinsichtlich Geräteliste, Modusaktionen, Liveupdates und Prioritätenreihenfolge geprüft. Die Kommunikation mit einer echten Symcon-Kachel muss weiterhin in der Testumgebung überprüft werden.


### Version 0.4: Ereignisregelung und Diagnose

Die Kachel enthält keine eigene Überschrift und keine „Bereit“-Anzeige mehr. Ihre Schriftart ist Tahoma; den Kacheltitel verwaltet die Symcon-Visualisierung.

**Vorzeichen der gemessenen Batterie-Leistung** ist eine eigene Auswahl mit „Positiv = Entladen“ oder „Positiv = Laden“. Sie betrifft ausschließlich die zugeordnete Istleistungsvariable. Die Vorzeichen der geschriebenen Sollwerte werden unabhängig eingestellt.

Die Bedingungsauswahl samt Variablenregeln ist vollständig entfernt. Alte Bedingungen werden ignoriert; zum Anlegen eines Verbrauchers wird keine Bedingungsvariable benötigt. Der Aktionssollwert bleibt für ein steuerbares Gerät erforderlich. Die gemessene Verbraucherleistung ist optional und wird ohne Zuordnung geschätzt. Auch Bereitschaftsvariable und Bereitschaftswert wurden entfernt.

**Verteilung: Gleichmäßig oder Reihenfolge** bestimmt die Leistungszuteilung. Bei Reihenfolge erhalten kleinere Nummern zuerst Leistung; größere werden zuerst reduziert. Bei gleichmäßiger Verteilung spielt diese Reihenfolge keine Rolle. Technische Geräte- und Laufzeitgrenzen bleiben wirksam.

**Regelung auslösen** bietet Sekundenintervall oder Änderung der Netzvariable. Im Änderungsmodus ist der Timer aus und nur eine tatsächliche Wertänderung löst einen Zyklus aus. Ein identischer, erneut übermittelter Messwert löst nicht aus. In diesem Modus wird auch ein Messwertalterfehler erst beim nächsten Zyklus festgestellt; es gibt keinen zusätzlichen Überwachungstimer.

Unter den Aktionen des Instanzformulars **Diagnosebericht erstellen** wählen. Der Bericht erscheint als Text und in der Stringvariable **Diagnosebericht**; vollständig kopieren und bei Problemen weitergeben. Er enthält Version, Einstellungen, verwendete Variablen mit Typ/Wert/Alter, letzte Fehler und die letzten 40 Diagnoseereignisse einschließlich Schreibversuchen. Er liest ausschließlich relevante Moduldaten, keine Prozessumgebung oder Zugangsdaten. Die Diagnose löst keine Geräteaktionen aus.


### Version 0.5: Optionale Bedingungen und Batterie als Puffer

Die Verbraucherliste besitzt jetzt einen Bearbeitungsdialog. **Bedingung verwenden** ist standardmäßig aus. Erst mit diesem Haken wird der Bedingungseditor sichtbar und seine Regel geprüft; ohne Haken ist keine Bedingungsvariable nötig. Leere Bedingungen sind freigegeben. Die Istleistungsvariable bleibt optional.

Beim Wechsel zwischen Watt/kW-Sollwert und Ampere werden Maximum und Minimum über Spannung und Phasenzahl umgerechnet; die Ampere-Schrittweite startet bei 1 A. Die Feldbeschriftungen folgen der gewählten Einheit. Bei Ein/Aus und Skalensteuerung werden deren relevanten Einstellungen angezeigt. Werte immer anhand des Geräts prüfen: Für eine typische 11-kW-Wallbox sind 16 A Maximum, 6 A Minimum und 1 A Schrittweite passend. In älteren Konfigurationen bereits fälschlich gespeicherte Werte wie 11.000 A werden nicht als reale Gerätegrenze übernommen; diese sind manuell zu korrigieren.

Die globale Option **Batterie zur Pufferung der Verbraucher nutzen** ist standardmäßig aktiv. Die maximal zulässige Entladung erhöht das Leistungsbudget, auch wenn der Netzbezug bereits im Totbereich liegt. Die Batterie liefert nur die anschließend benötigte Differenz zwischen Netzbudget und Verbraucherbedarf. SOC- und Leistungsgrenzen begrenzen diesen Puffer. Ohne diese Freigabe wird vorhandene Batterieladung zugunsten der Verbraucher reduziert, aber keine zusätzliche Entladung zum Aktivieren von Verbrauchern eingeplant. Eine Pufferung kann die Batterie zum Laden der Wallboxen entladen; das SOC-Minimum bleibt die Untergrenze.

**Maximales Alter der Verbraucher-Istleistung** ist getrennt von der Altersgrenze für Netz/Batterie/SOC. Standard 0 deaktiviert ausschließlich die Altersprüfung für optionale Verbraucher-Messwerte, deren Geräte häufig nur bei Wertänderungen senden. Dann wird der letzte numerische Wert verwendet, was eine funktionierende Geräteanbindung voraussetzt. Wer Ausfälle dieser Rückmeldung erkennen möchte, trägt eine passende positive Grenze ein. Die Altersprüfung der Netz-/Batteriemessung bleibt bestehen. Die Diagnose enthält für alle Messwerte weiterhin ihr tatsächliches Alter.

Die Kachel hat zusätzlich 64 Pixel Abstand oben, damit der Inhalt unter dem vom Benutzer vergebenen Titel beginnt. Die 0.5-Tests enthalten den Fall aus dem bereitgestellten Diagnosebericht mit vier Wallboxen und Batteriepuffer.
