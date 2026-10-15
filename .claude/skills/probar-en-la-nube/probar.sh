#!/bin/bash
# Despliega el plugin del repo en el WordPress de pruebas de $NUBE y lanza tests/*.php (o los nombrados).
# Uso: NUBE=<carpeta> .claude/skills/probar-en-la-nube/probar.sh [nombre ...]
: "${NUBE:?Define NUBE (la carpeta donde se montó el WordPress con montar.sh)}"
SKILL="$(cd "$(dirname "$0")" && pwd)"
REPO="${REPO:-$(cd "$SKILL/../../.." && pwd)}/asde-area-privada"
DEST="$NUBE/wp/wp-content/plugins/asde-area-privada"
WP="$NUBE/bin/wp"
rm -rf "$DEST" && cp -r "$REPO" "$DEST"
$WP plugin is-active asde-area-privada >/dev/null 2>&1 || $WP plugin activate asde-area-privada >/dev/null
# La instantánea de fichas (y demás cachés) que haya dejado una sesión web con el CRM de demostración
# cambiaría lo que leen las pruebas.
$WP transient delete --all >/dev/null 2>&1
if [ $# -eq 0 ]; then
	set -- $(cd "$DEST/tests" && ls *.php | sed 's/\.php$//')
fi
total_ok=0; total_fail=0; mal=""
for t in "$@"; do
	out=$($WP eval-file "$DEST/tests/$t.php" 2>&1)
	ok=$(printf '%s\n' "$out" | grep -c '^OK ')
	fail=$(printf '%s\n' "$out" | grep -c '^FAIL ')
	total_ok=$((total_ok+ok)); total_fail=$((total_fail+fail))
	printf '%-24s %4d OK %3d FAIL\n' "$t" "$ok" "$fail"
	if [ "$fail" -gt 0 ] || ! printf '%s\n' "$out" | grep -q '^OK '; then
		mal="$mal $t"
		printf '%s\n' "$out" | grep -v '^OK \|^    #\|^#[0-9]\|thrown in\|Stack trace' | head -30 | sed 's/^/    /'
	fi
done
echo "TOTAL: $total_ok OK, $total_fail FAIL"
[ -z "$mal" ] && echo "TODO OK" || echo "Con fallos:$mal"
