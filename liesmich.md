# wp-dansal

WordPress-Plugin zur Verwaltung von Tanzevents und -orten, das [dansal](https://github.com/ademant/dansal) als Backend für Speicherung und Veröffentlichung nutzt.

## Verbindung mit dansal herstellen

1. Öffnen Sie in dansal `/admin/users` und klicken Sie auf **Connect link** neben dem Publisher-Eintrag Ihrer Organisation (oder erstellen Sie einen).
2. Gehen Sie in WordPress zu **Einstellungen → Dansal**, fügen Sie den Einmalkink unter "Connect via Link" ein und klicken Sie auf **Connect** — dies trägt automatisch die Basis-URL, Organisation und den API-Schlüssel ein. Nutzen Sie "Test Connection", um die Verbindung zu prüfen.
   - Alternativ können Sie "Manual connection (advanced)" erweitern und manuell eine Basis-URL/Organisations-ID/API-Schlüssel eingeben, die Sie bereits aus `dansal_admin` oder `POST /api/v1/publishers` haben.

## Event- und Ortsverwaltung

Das Plugin fügt die benutzerdefinierten Beitragstypen **Tanzorte (Dance Locations)** und **Tanzevents (Dance Events)** hinzu, die im normalen WordPress-Admin bearbeitet werden können.

Beim Anlegen eines Orts sucht das Plugin die Adresse über OpenStreetMap (Nominatim) und prüft dann in dansal auf bestehende Orte (nach OSM-ID, dann nach Nähe), um Duplikate zu vermeiden. Statt ein Duplikat zu erstellen, wird angeboten, Ihre Organisation dem bestehenden Ort zuzuweisen.

Das Speichern eines Events oder Orts synchronisiert es automatisch mit dansal (Erstellung beim ersten Speichern, Update bei jedem weiteren Speichern) unter Verwendung eines Publisher-API-Schlüssels, der auf eine Organisation beschränkt ist.

Events können mit dem Shortcode `[dansal_events]` angezeigt werden: anstehende Events als Liste oder als Monatskalender (`view="list"` oder `view="calendar"` sowie die Attribute `location`, `tag`, `limit`, `show_past`). Der Shortcode `[dansal_locations]` gibt ein Verzeichnis der Orte mit einer selbst gehosteten Leaflet-Karte aus. Einzelne Vorlagen stehen für einzelne Events und Orte zur Verfügung.

`[dansal_events]` kann auch Events *anderer* Organisationen/Städte auf derselben dansal-Instanz einblenden, statt nur die lokal synchronisierten eigenen Events — über `org="slug1,slug2"`, `country="de,fr"`, `bbox="minLng,minLat,maxLng,maxLat"`, `lat`/`lon`/`radius_km` sowie `exclude_own_org="1"`. Diese Events werden live über dansals öffentliches `GET /api/v1/events` abgerufen (nicht als lokale Beiträge synchronisiert) und mit denselben Listen-/Kalendervorlagen dargestellt; Events/Orte/Organisationen ohne eigene Seite auf dieser WordPress-Site verlinken stattdessen auf dansal-web, über die optionale Einstellung **Dansal Web URL** (fällt auf die API-Basis-URL zurück, falls leer).

## Template-Überschreibungen im Theme

Jede der Plugin-Vorlagen kann überschrieben werden, indem Sie sie in Ihr (Child-)Theme in einem Unterverzeichnis `dansal/` kopieren. `locate_template()` wählt zuerst die Kopie im Child-Theme, dann die des Parent-Themes und fällt schließlich auf die Standardvorlage des Plugins zurück:

- `dansal/single-dansal_event.php`
- `dansal/single-dansal_location.php`
- `dansal/archive-dansal_event.php`
- `dansal/archive-dansal_location.php`
- `dansal/page-dansal-locations.php`
- `dansal/page-dansal-calendar.php`

## Karte/Kalender auf einer Seite platzieren

Statt der automatischen Archiv-URLs können Sie die Ortskarte oder den Event-Kalender auf jeder Seite platzieren: **Seiten → Neu erstellen → Seiten-Attribute → Vorlage** und dann **Dansal: Locations Map** oder **Dansal: Events Calendar** auswählen. Titel und Inhalt der Seite werden normal dargestellt; die Karte/der Kalender wird darunter eingefügt.

## Übersetzungen

Übersetzbare Strings befinden sich unter dem Textdomain `wp-dansal`. Übersetzer können von `languages/wp-dansal.pot` ausgehen und `.po`/`.mo`-Dateien daneben ablegen (`languages/wp-dansal-{locale}.po`) oder unter `wp-content/languages/plugins/` platzieren. Die POT-Datei kann mit `make pot` neu generiert werden (benötigt [wp-cli](https://wp-cli.org/)).

## Lokale Entwicklung

`make zip` baut ein Release-Zip in `dist/`. Um stattdessen gegen eine lokale WordPress-Installation zu iterieren, kopieren Sie `.env.example` nach `.env`, setzen `WP_PLUGIN_DIR` (das `wp-content/plugins`-Verzeichnis dieser Installation) und `WP_OWNER` (der `user:group`, unter dem der Webserver läuft, z. B. `www-data:www-data`), und führen dann `make deploy` aus — dies synchronisiert das gebaute Plugin per rsync nach `$WP_PLUGIN_DIR/wp-dansal/` und setzt den Besitzer auf `WP_OWNER`. `.env` ist in `.gitignore` eingetragen. `make help` listet alle Targets auf.

## Hinweise

- Kartentiles werden live vom OpenStreetMap-Tile-Server geladen (die Leaflet-Bibliothek selbst ist gebündelt, aber Tiles können praktisch nicht selbst gehostet werden). Jede Tile-Anfrage wird mit `Referrer-Policy: origin` gesendet (nur Schema+Host der Seite, nicht der vollständige Pfad/Query) — OSMs Tile-Server verlangen einen Referer und blockieren Anfragen ganz ohne. Um Tiles stattdessen über einen selbst gehosteten oder kostenpflichtigen Proxy zu leiten, filtern Sie `wpd_tile_url_template` (und bei Bedarf `wpd_tile_attribution` / `wpd_tile_max_zoom` / `wpd_tile_referrer_policy`):

  ```php
  add_filter( 'wpd_tile_url_template', function () {
      return 'https://tiles.example.com/{z}/{x}/{y}.png';
  } );
  ```
- In der dansal-Dokumentation `API.md` wird `PATCH /api/v1/events/{id}` für Updates beschrieben, aber der Server registriert derzeit nur `PUT /api/v1/events/{id}` — dieses Plugin verwendet `PUT`. Es wäre sinnvoll, dies irgendwann in den dansal-Dokumentationen/Routen anzupassen.
