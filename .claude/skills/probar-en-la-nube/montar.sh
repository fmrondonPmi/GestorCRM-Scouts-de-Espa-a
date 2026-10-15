#!/bin/bash
# Monta en $NUBE un WordPress de pruebas con SQLite (sin MySQL), con WP-CLI, un tema clásico con
# menú y el plugin del repo activado y configurado como el Local del usuario.
# Uso: NUBE=<carpeta de trabajo de la sesión> .claude/skills/probar-en-la-nube/montar.sh
set -e
: "${NUBE:?Define NUBE: una carpeta de trabajo de la sesión (p. ej. <scratchpad>/nube)}"
SKILL="$(cd "$(dirname "$0")" && pwd)"
REPO="${REPO:-$(cd "$SKILL/../../.." && pwd)}"
mkdir -p "$NUBE/descargas" "$NUBE/bin"

# Descargas (en su propia carpeta: son datos de fuera, no se ejecuta nada desde ella).
cd "$NUBE/descargas"
[ -s wp.zip ] || curl -sSL -o wp.zip https://downloads.wordpress.org/release/es_ES/latest.zip
[ -s sqlite.zip ] || curl -sSL -o sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
[ -s tt1.zip ] || curl -sSL -o tt1.zip https://downloads.wordpress.org/theme/twentytwentyone.latest-stable.zip
[ -s "$NUBE/bin/wp-cli.phar" ] || curl -sSL -o "$NUBE/bin/wp-cli.phar" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

# WordPress nuevo (borra el anterior de $NUBE/wp, si lo había).
rm -rf "$NUBE/wp" "$NUBE/wp-tmp"
mkdir "$NUBE/wp-tmp" && (cd "$NUBE/wp-tmp" && unzip -q ../descargas/wp.zip) && mv "$NUBE/wp-tmp/wordpress" "$NUBE/wp" && rmdir "$NUBE/wp-tmp"
(cd "$NUBE/wp/wp-content/plugins" && unzip -q "$NUBE/descargas/sqlite.zip")
(cd "$NUBE/wp/wp-content/themes" && unzip -q "$NUBE/descargas/tt1.zip")
SQLITE="$NUBE/wp/wp-content/plugins/sqlite-database-integration"
sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "$SQLITE/db.copy" > "$NUBE/wp/wp-content/db.php"
cp "$SKILL/wp-config.php" "$NUBE/wp/wp-config.php"
printf '#!/bin/sh\nexec php -d memory_limit=1024M %s/bin/wp-cli.phar --allow-root --path=%s/wp "$@"\n' "$NUBE" "$NUBE" > "$NUBE/bin/wp"
chmod +x "$NUBE/bin/wp"
WP="$NUBE/bin/wp"

$WP core install --url=http://localhost:8099 --title=Pruebas --admin_user=admin --admin_password="$(php -r 'echo bin2hex(random_bytes(12));')" --admin_email=admin@example.invalid --skip-email
$WP theme activate twentytwentyone
$WP menu create Principal >/dev/null
$WP menu location assign principal primary
$WP rewrite structure '/%postname%/'
cp -r "$REPO/asde-area-privada" "$NUBE/wp/wp-content/plugins/"
$WP plugin activate asde-area-privada
$WP eval-file "$SKILL/preparar.php"
$WP rewrite flush
echo "Listo: $NUBE/wp · pruebas: NUBE=$NUBE $SKILL/probar.sh [nombre…]"
