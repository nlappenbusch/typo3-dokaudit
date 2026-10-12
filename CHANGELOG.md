# Changelog – DokAudit für TYPO3 (EXT:dokaudit)

## 1.2.2 – 2026-10-10
- Behoben: In Composer-Installationen meldete das Modul „Update verfügbar“, weil TYPO3 statt der Version den Git-Zweig (z. B. „dev-main“) liefert. Die Version kommt jetzt aus ext_emconf.php.

## 1.2.1 – 2026-10-10
- Code nach den TYPO3 Coding Standards formatiert.
- Lizenzdatei (GPL-2.0-or-later), Changelog, README mit Demo- und Download-Links, Composer-Metadaten.

## 1.2.0 – 2026-10-10
- Einstellungen direkt im Modul **Datei → DokAudit**: API-Schlüssel wird beim Speichern geprüft, die zugehörige Organisation angezeigt.
- Update-Hinweis, sobald DokAudit eine neuere Version meldet (mit Composer-Befehl und ZIP-Download).
- Hinweise auf noch ungeprüfte PDFs der Dateiliste und auf knappes Guthaben.
- Version und TYPO3-Version im User-Agent (für die Schlüsselübersicht im DokAudit Hub).

## 1.1.0 – 2026-10-10
- Siegel je Dokument (PDF/UA-1, WCAG 2.2, PDF/A-2a) und Link zum Prüfbericht im DokAudit Hub.
- DokAudit-Logo, Kennzahlen und „Zum DokAudit Hub“ in der Übersicht.
- Fertige Ergebnisse werden beim Öffnen des Moduls sofort abgeholt.

## 1.0.0
- Erste Fassung: PDFs der Dateiliste finden, an DokAudit übergeben, durch die barrierefreie Fassung ersetzen
  (sys_file-UID bleibt), Original sichern und wiederherstellen; Konsolenbefehle `dokaudit:sync` und `dokaudit:restore`.
