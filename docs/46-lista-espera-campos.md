# 46 · Lista de espera: campos propios del formulario (v0.38.0)

Petición del usuario (2026-10-16): además de los datos obligatorios de la lista de espera, cada
grupo puede añadir al formulario público preguntas suyas, obligatorias u opcionales, y las
respuestas se guardan cifradas con el email de quien se apunta como clave, para que al borrar
la inscripción desaparezca también lo necesario para descifrarlas.

## Qué hay

- **Editor** en *Lista de espera* (cuadro plegable «Campos propios del formulario», solo
  Secretaría y Coordinación, permiso `asde_lista_espera_alta`): por campo, **etiqueta
  (obligatoria)**, **tipo** (texto corto, texto largo, número, fecha, casilla) y **opcional /
  obligatorio**; «Quitar» por fila; hasta 12 campos por grupo. Las definiciones no son datos
  personales: opción `asde_ap_espera_campos` (por número de grupo). Clase `ListaEspera\CamposExtra`.
- **Formulario público** (`inscripcion_espera`): un apartado «Más datos que pide el grupo» con
  los campos y su etiqueta; los opcionales lo dicen; los obligatorios se exigen en el servidor.
- **Ver**: el equipo en la ficha de la inscripción y la persona en su página personal.
- **Cifrado**: columna nueva `extra_enc` (`dbDelta`). Clave = `Crypto::encrypt_contexto` con el
  contexto `espera-extra|<email>|<sal de la fila>` (más la clave del sitio, que no está en la
  base de datos). Sin el email y la sal de la fila no se descifra; ambos están solo en la propia
  inscripción (el email, cifrado con la clave general), así que **borrarla (alta en el CRM, petición
  de la persona, equipo o caducidad) borra respuestas y llave a la vez**.
- Junto a cada respuesta se guarda la **etiqueta que tenía la pregunta** al contestarla: quitar o
  renombrar un campo no cambia lo ya contestado.
- La auditoría no guarda respuestas.

## Supuestos (por confirmar)

- **P-168**: la clave no es *solo* el email (sería adivinable) sino email + sal + clave del sitio;
  el efecto pedido (borrar la inscripción deja sin llave) se cumple igual. ¿Vale?
- **P-169**: las respuestas propias **no pasan al CRM** al dar de alta (no hay campo donde ponerlas);
  se pierden al borrar la inscripción. ¿Hay algún campo que deba ir a la ficha?
- **P-170**: tipos disponibles (texto, texto largo, número, fecha, casilla). ¿Falta alguno
  (desplegable con opciones)?

## Pruebas

`tests/lista-espera.php` apartado H (19 comprobaciones): permisos, definición y validación del
editor, formulario público, obligatorios, cifrado en la base de datos, descifrado solo con su
email y su sal, etiquetas conservadas, borrado. Suite de la lista de espera: 85 OK. Plugin Check: 0.
