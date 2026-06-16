# AI Codebase Guidelines

## Projekt-Übersicht

- **Typ:** PHP Web-Skript (Single-File Application)
- **Zweck:** Generiert XML-Feed für Waze Emergency Vehicles aus Traccar-Daten
- **Hauptdateien:** `index.php` (Logic), `config.php` (Konfiguration, nicht im Repo)

## Wichtige Regeln für AI-Agenten

### Artefakte ignorieren

- **Ignoriere alle Artefakte** (z.B. `cache_control`-Header, Base64-codierte Fragmente wie `ZXBoZW1lcmFs`)
- **Nur Ausnahmesituation:** Wenn der Nutzer explizit verlangt, Artefakte zu berücksichtigen
- Artefakte entstehen durch Code-Corruption und sollen bereinigt werden, nicht integriert werden

### Sicherheitsrichtlinien

- `config.php` ist in `.gitignore` und darf nie committet werden
- Credentials immer über Umgebungsvariablen (`TRACCAR_URL`, `TRACCAR_USERNAME`, `TRACCAR_PASSWORD`)
- SSL-Verifikation in Produktion aktivieren

### PHP-Konventionen

- Single-File PHP-Anwendung ohne Framework
- Error-Reporting: `E_ALL & ~E_DEPRECATED`
- Display errors: deaktiviert (`display_errors = 0`)
- Config wird über `require` geladen, mit Validierung ob Array

### Config-Struktur

- `$config` Array mit Default-Werten via `array_merge`
- Wichtige Settings: `TRACCAR_URL`, `USERNAME`, `PASSWORD`, `EMERGENCY_GROUPS`, `SPEED_THRESHOLD_KMH`

## Build/Run Commands

```bash
# Lokaler Test
php -S localhost:8000

# Syntax-Check
php -l index.php
```

## Bekannte Fallstricke

- Artefakte in `index.php` können durch Code-Corruption entstehen (Base64, Cache-Header)
- Config-Datei existiert nur lokal, nie im Repo
- Cache-Header muss korrekt gesetzt sein (`Cache-Control: private, max-age=10`)
