Helper-Installation

1. Diese index.php in den Dokumentenstamm der Branding-Domain kopieren.
2. Die Datei muss dort index.php heißen.
3. Oben in index.php $API_URL auf die zentrale API setzen und $API_KEY auf den API-Key des passenden Projekts.
4. .htaccess in denselben Dokumentenstamm kopieren.
5. HTTPS, PHP und die cURL-Erweiterung müssen auf dem Hosting verfügbar sein.

Die Branding-Domain muss nicht auf den zentralen Server zeigen. Der Helper sendet nur ausgehende HTTPS-Anfragen an die zentrale API und leitet danach mit HTTP 302 auf die gespeicherte Ziel-URL weiter.
