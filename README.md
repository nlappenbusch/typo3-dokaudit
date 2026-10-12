# DokAudit für TYPO3 (EXT:dokaudit)

**Live-Demo:** [typo3.dokaudit.de](https://typo3.dokaudit.de) · **Download:** [aktuelle Version als ZIP](https://dokaudit.de/api/v1/plugins/typo3/download)
· **Composer:** `dokaudit/typo3-dokaudit` über ein Pfad-Repository (siehe Installation) · **Lizenz:** GPL-2.0-or-later · **Änderungen:** [CHANGELOG.md](CHANGELOG.md)

Prüft die PDFs in der TYPO3-Dateiliste mit DokAudit auf Barrierefreiheit (PDF/UA-1, WCAG 2.2, BITV 2.0)
und **ersetzt sie durch die korrigierte Fassung, ohne dass sich etwas an der Website ändert**:

- Die Datei behält ihre `sys_file`-UID (`ResourceStorage::replaceFile`), alle Links, Inhaltselemente und
  Dateiverweise bleiben gültig.
- Das Original wird vorher in `/_dokaudit_backup/` gesichert und lässt sich mit einem Klick (oder
  `dokaudit:restore`) wiederherstellen.
- Ersetzt wird nur, wenn die Punktzahl wirklich steigt (`minGain`).
- Neue oder ersetzte PDFs werden automatisch für den nächsten Abgleich eingereiht.

TYPO3 12.4 LTS und 13.4 LTS, PHP ≥ 8.1. Getestet mit TYPO3 13.4 (SQLite) gegen DokAudit API v1.

## Installation

Standardweg: [ZIP herunterladen](https://dokaudit.de/api/v1/plugins/typo3/download) und im Erweiterungsmanager
hochladen (der Ordner im ZIP heißt `dokaudit`).

Composer-Installationen (ohne Upload im Erweiterungsmanager): Das Paket wird nicht über Packagist verteilt. Das ZIP
in einen Ordner des Projekts entpacken und als Pfad-Repository einbinden:

```bash
composer config repositories.dokaudit '{"type":"path","url":"pfad/zu/dokaudit"}'
composer require dokaudit/typo3-dokaudit:@dev
vendor/bin/typo3 extension:setup        # legt die Tabelle tx_dokaudit_file an
```

## Einrichtung

1. Im DokAudit Hub unter **Integrationen → API-Schlüssel** einen Schlüssel mit Recht `write` anlegen.
2. In TYPO3 im Modul **Datei → DokAudit → Einstellungen** API-Adresse und Schlüssel eintragen. Der Schlüssel wird
   beim Speichern geprüft, die zugehörige Organisation angezeigt. Alle Optionen stehen zusätzlich unter
   **Einstellungen → Erweiterungskonfiguration → dokaudit**:

| Einstellung | Bedeutung |
|---|---|
| `apiUrl` | `https://dokaudit.de` (bzw. eigene Instanz) |
| `apiKey` | `dka_live_…` (alternativ Umgebungsvariable `DOKAUDIT_API_KEY`) |
| `mode` | `replace` = prüfen und ersetzen · `check` = nur prüfen, nichts ersetzen |
| `storages` / `folders` | Einschränken auf Speicher-UIDs bzw. Ordner, z. B. `/formulare/,/satzungen/` |
| `excludeFolders` | nie anfassen (Standard: Sicherung, `_processed_`, `_temp_`) |
| `minGain` | Mindest-Punktgewinn für das Ersetzen (Standard 1) |
| `autoQueue` | neue/ersetzte PDFs automatisch einreihen |
| `backupFolder` | Sicherungsordner im selben Speicher |
| `maxSizeMb` | größere PDFs überspringen |

3. Scheduler-Aufgabe **„Konsolenbefehl ausführen“ → `dokaudit:sync`** anlegen, z. B. alle 10 Minuten.

## Bedienung

- **Datei → DokAudit** (Backend-Modul, nur Admins): Verbindung und Guthaben, Kennzahlen, Liste aller
  erfassten PDFs mit Punktzahl vorher/nachher, „Jetzt abgleichen“, „Original wiederherstellen“,
  „Erneut prüfen“.
- Konsole:

```bash
vendor/bin/typo3 dokaudit:sync --limit=20 --wait=120   # abgleichen und bis zu 2 min auf Ergebnisse warten
vendor/bin/typo3 dokaudit:restore 123                  # Original von sys_file 123 zurückspielen
```

## Ablauf je Datei

`new` → (Upload, Vollautomatik) → `submitted` → DokAudit prüft, repariert, prüft nach →
`replaced` (ersetzt, Original gesichert) oder `checked` (nur geprüft / keine Verbesserung).
Weitere Zustände: `waiting` (wartet auf Guthaben), `error`, `restored`, `skipped`.
Wird eine Datei während der Verarbeitung neu hochgeladen, verwirft die Erweiterung das Ergebnis und
reiht die neue Fassung ein. Abrechnung wie im Hub: 1 Credit je angefangene 5 Seiten.

## Datenschutz

Übertragen werden nur die PDF-Dateien selbst (HTTPS, Bearer-Schlüssel). Die Verarbeitung erfolgt auf
den Servern von DokAudit (Deutschland) nach dem Auftragsverarbeitungsvertrag der Organisation.

## English summary

DokAudit for TYPO3 checks the PDFs in the TYPO3 file list for accessibility (PDF/UA-1, WCAG 2.2, EN 301 549)
using the DokAudit service and replaces them **in place** with the remediated version: the `sys_file` record,
URLs and all references stay the same, the original is backed up and can be restored with one click.
Requires a DokAudit account and an API key (each key belongs to one organisation and is billed there).
TYPO3 12.4 LTS and 13.4 LTS, PHP 8.1+. Licence: GPL-2.0-or-later.
