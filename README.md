# ShortURL Manager

Zentraler PHP/MySQL Short-URL-Manager mit beliebig vielen Branding-Domains.

## Voraussetzungen

- PHP 8.2+
- MySQL/MariaDB
- PDO MySQL
- cURL
- HTTPS
- Apache/.htaccess oder entsprechendes Routing

## Installation

1. Datenbank anlegen.
2. `schema.sql` importieren.
3. `config.php` mit Datenbankzugang, Base-URL und einem langen `api_secret` füllen.
4. Admin-Benutzer anlegen:

```sql
INSERT INTO admin_users (username, password_hash, created_at)
VALUES ('admin', '$2y$10$HIER_EINEN_PASSWORD_HASH_EINSETZEN', NOW());
```

Ein Hash kann erzeugt werden mit:

```bash
php -r "echo password_hash('DEIN_PASSWORT', PASSWORD_DEFAULT), PHP_EOL;"
```

5. Dateien auf den zentralen Server hochladen.
6. `/admin/login.php` öffnen.
7. Projekt anlegen. Der einmalig angezeigte API-Key wird für den Helper benötigt.
8. Auf der Branding-Domain `helper/index.php` als `index.php` hochladen und API-URL + API-Key eintragen.
9. `.htaccess` aus `helper/` ebenfalls hochladen.

## Architektur

Der zentrale Server verwaltet Projekte, Domains, Links und Statistiken.

Jede Branding-Domain hat nur einen kleinen Helper. Ein Shortcode ist innerhalb seines Projekts eindeutig; derselbe Slug darf in einem anderen Projekt erneut verwendet werden.

Beispiel:

- `go.projekt-a.de/abc` -> Ziel A
- `go.projekt-b.de/abc` -> Ziel B

## Bot-Erkennung

Bekannte Crawler, Social Preview Bots, Link Scanner und typische HTTP-Clients werden nicht als echte Klicks gezählt. Sie werden jedoch als Bot-Anfrage gespeichert, damit die Differenz sichtbar bleibt.

Zusätzlich wird derselbe IP-Hash auf demselben Link innerhalb von 30 Sekunden nicht erneut gezählt.

Die IP wird nicht im Klartext gespeichert, sondern HMAC-gehasht.

## Datenschutz

Die mitgelieferte Statistik ist bewusst datensparsam, ersetzt aber keine rechtliche Prüfung. Für produktive Nutzung sollten insbesondere Aufbewahrungsdauer, Datenschutzinformation und ggf. Einwilligung/Interessenabwägung passend zum Einsatzzweck geprüft werden.

## Sicherheit

- PDO Prepared Statements
- CSRF-Schutz im Admin
- `password_hash()` / `password_verify()`
- API-Key als HMAC-Hash in der Datenbank
- Session-Cookie mit HttpOnly/SameSite
- HTTPS für API und Branding-Domains
- keine Weiterleitung anhand frei übergebener Ziel-URLs

### Benutzerverwaltung

Nach dem ersten Login steht unter **Benutzer** eine Verwaltung für die Admin-Konten zur Verfügung. Neue Konten benötigen mindestens 12 Zeichen beim Passwort. Passwörter werden ausschließlich mit PHP `password_hash()` gespeichert und beim Login mit `password_verify()` geprüft.

Der aktuell eingeloggte Benutzer kann sein eigenes Passwort ändern. Ein Benutzer kann sich nicht selbst löschen und der letzte vorhandene Admin kann ebenfalls nicht gelöscht werden.

## Linkverwaltung (v4)

Die Kurzlink-Verwaltung bietet Suche über Kurzcode, Titel, Ziel-URL, Projekt und Domain sowie Filter nach Projekt und Aktiv-Status. Beim Erstellen kann der Kurzcode leer bleiben; er wird dann aus Titel oder URL-Pfad abgeleitet und bei Bedarf automatisch um eine Nummer ergänzt. Die vollständige Ziel-URL wird als String gespeichert, ohne Query-Parameter neu zusammenzusetzen – das ist auch für Affiliate- und UTM-Links wichtig.

Die Vorschau zeigt die resultierende Kurz-URL. Kurz- und Ziel-URL können kopiert werden; „Ziel-URL testen“ öffnet das gespeicherte Ziel direkt in einem neuen Tab und erzeugt daher keinen Klick über den Kurzlink. Kurzlinks lassen sich direkt in der Liste aktivieren/deaktivieren. Das Löschen entfernt auch die mit dem Link verknüpften Klickdaten.


## Projekt-Einrichtung (v5)

In **Projekte → Einrichtung** findest du die für jede Branding-Domain benötigten Installationsdaten, den zentralen API-Endpunkt und eine Schritt-für-Schritt-Anleitung für den Helper. Ein API-Key kann dort neu erzeugt werden; der neue Schlüssel wird einmalig angezeigt und der bisherige wird sofort ungültig. Nach einem Reset muss der Helper auf der Branding-Domain mit dem neuen Schlüssel aktualisiert werden.

Die Helper-Datei wird weiterhin auf dem Webhosting der Branding-Domain installiert. Die Branding-Domain muss nicht per DNS auf den zentralen Manager zeigen. Sie benötigt lediglich PHP, HTTPS und cURL für ausgehende HTTPS-Anfragen an die zentrale API.


## Performance update (v6)

The redirect API now resolves the project and active link in a single SQL query. The repeat-click check uses an indexed existence lookup instead of counting matching rows. The new index is included in `schema.sql`.

For existing installations, run `upgrade-v6.sql` once in the same database as the manager. If the index already exists, do not run the migration a second time. Keep `config.php` and the helper API keys unchanged.

If a redirect still takes around 1–2 seconds, measure the request in the browser's Network panel. The main suspects are DNS/TLS connection setup from the branding host to the central API, remote database latency, and hosting-level PHP cold starts. This code change reduces database work but cannot eliminate the required helper-to-API network roundtrip.

## v7: Redirect timing diagnostics (temporary)

This version can log timing information without showing anything to visitors. It is intended for short diagnostic tests only.

1. Upload/replace `api/resolve.php` on the central manager.
2. Upload/replace `helper/index.php` on each branding domain being tested.
3. In `helper/index.php`, set `$TIMING_DEBUG = true;` for the test. It is enabled by default in this diagnostic package.
4. Open one or two short links, then inspect the PHP error logs on **both** the branding-domain hosting and the central manager hosting.
5. Match entries by the same 12-character `id`.
6. After testing, set `$TIMING_DEBUG = false;` in the helper. The API only emits detailed timing logs when the helper sends the diagnostic flag.

Helper log fields include DNS, connection, TLS, API time-to-first-byte, API total time and helper elapsed time. API log fields include bootstrap (including DB connection/session startup), project/link lookup, click tracking/transaction and total API time. No API key or destination URL is written to these diagnostic log lines.

The diagnostic data is written via PHP `error_log()`. The exact log location depends on the hosting provider/Plesk configuration. Do not leave diagnostic logging enabled longer than needed.


## v8: Admin-Anmeldung und Session-Härtung

Für bestehende Installationen `upgrade-v8-auth.sql` einmal in der Manager-Datenbank ausführen. Bei Neuinstallationen ist die Tabelle bereits in `schema.sql` enthalten. Die Anmeldung begrenzt Fehlversuche auf maximal 5 pro IP-/Benutzername-Kombination und 20 pro IP innerhalb eines rollierenden 15-Minuten-Fensters. Alte Einträge werden gelegentlich automatisch entfernt.

Die Session verwendet Strict Mode, ausschließlich Cookies und HttpOnly/SameSite. Das Secure-Flag wird anhand der vom Webserver bereitgestellten HTTPS-Informationen gesetzt; wenn HTTPS hinter einem Reverse Proxy terminiert wird, muss sichergestellt sein, dass PHP die Verbindung als HTTPS erkennt. Die Abmeldung erfolgt per POST mit CSRF-Token. Die Admin-Seiten senden zusätzliche Sicherheitsheader und deaktivieren Caching.


## v9: Race-Condition-Schutz beim Klicktracking

Die API koordiniert die 30-Sekunden-Deduplizierung jetzt mit einem MySQL/MariaDB Named Lock, der nur für die Kombination aus Link-ID und IP-Hash gilt. Dadurch können zwei parallele Anfragen derselben Quelle denselben Klick nicht gleichzeitig als neu einstufen. Es ist keine zusätzliche SQL-Migration erforderlich.

Kann der Named Lock nicht innerhalb einer Sekunde erworben werden oder ist er auf dem Datenbankserver nicht verfügbar, wird der Klick weiterhin protokolliert, aber nicht zum Zähler addiert. Das priorisiert die Verfügbarkeit des Redirects vor einer möglicherweise doppelten Zählung. Die vorhandenen Bot-Regeln, die Klickhistorie, der 30-Sekunden-Zeitraum und der bestehende Index `idx_clicks_dedupe` bleiben erhalten.

Nach dem Upload bitte einen normalen Kurzlink, einen unbekannten Slug und mehrere nahezu gleichzeitige Aufrufe testen. Der Code wurde auf PHP-Syntax geprüft; das tatsächliche Verhalten von `GET_LOCK()` sollte auf der verwendeten Plesk-MySQL/MariaDB-Instanz verifiziert werden.


## v10: Redirect-Verfügbarkeit bei Tracking-Fehlern

Nachdem ein aktiver Kurzlink gefunden wurde, verhindert ein Fehler beim Speichern des Klicks nicht mehr die Weiterleitung. Die API protokolliert einen knappen Fehlerdatensatz ohne IP-Hash, API-Key, Ziel-URL, User-Agent oder rohe SQL-Fehlermeldung und gibt weiterhin `success: true` sowie die aufgelöste Ziel-URL zurück. `tracking_recorded` zeigt ergänzend an, ob das Tracking gespeichert werden konnte; bestehende Helper ignorieren dieses zusätzliche Feld und bleiben kompatibel.

Wichtig: Fällt bereits die Datenbankverbindung oder der Link-Lookup aus, kann die API den Ziel-Link nicht auflösen; in diesem Fall bleibt ein Fehlerstatus erforderlich. Bei Tracking-Fehlern können Klickhistorie und Zähler unvollständig sein, während die Weiterleitung verfügbar bleibt.


## v11: Schutz sensibler Dateien im Webroot

Das Paket enthält eine `.htaccess` für Apache 2.4/Plesk. Sie deaktiviert Directory Listings und sperrt direkte HTTP-Zugriffe auf `config.php`, `config.example.php`, SQL-Schemata/Migrationen und die README.

**Installation:** Wenn im Installationsverzeichnis bereits eine `.htaccess` existiert, diese nicht blind überschreiben. Die Regeln aus der Paketdatei in die vorhandene Datei übernehmen. Bei Nginx-only-Konfigurationen greifen Apache-`.htaccess`-Regeln nicht; dort muss der Zugriff serverseitig in der Nginx-Konfiguration gesperrt werden.

Diese Maßnahme verhindert keinen Zugriff auf Dateien über kompromittierte PHP-Skripte und ersetzt nicht die bevorzugte Ablage von `config.php` außerhalb des öffentlich erreichbaren Document Roots. `config.php` wird weiterhin nicht mitgeliefert.


## v12: Datenbank- und Bootstrap-Fehlerbehandlung

Wenn die Konfiguration fehlt/unvollständig ist oder die Datenbankverbindung nicht aufgebaut werden kann, antwortet der Manager mit HTTP 503 und einer generischen Meldung. API-Aufrufe erhalten JSON (`service_unavailable`), Admin-Seiten eine einfache HTML-Meldung. Technische Zugangsdaten, DSNs und rohe PDO-Fehlermeldungen werden nicht an Besucher ausgegeben oder in das neue Diagnose-Log geschrieben. Die Verbindungsaufbauzeit ist auf 3 Sekunden begrenzt.

**Test:** Erst in einer Testumgebung prüfen. Nach dem Upload normale Admin- und Redirect-Funktion testen. Eine echte DB-Ausfallsimulation sollte nur in einer Testinstallation erfolgen. Bei Datenbankausfall kann ein Kurzlink nicht aufgelöst werden, da das Ziel dann nicht ermittelt werden kann.
