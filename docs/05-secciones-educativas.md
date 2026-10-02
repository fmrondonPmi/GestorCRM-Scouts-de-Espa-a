# 05 · Secciones educativas y estructura de ASDE

Fuentes: https://scout.es/quienes-somos/metodo-secciones/, https://baleares.scout.es/que-hacemos/, los Manuales del Educador de ASDE (2014) en biblioteca.scout.es y el Reglamento Interno de ASDE de 2016 (arts. 8.3, 16 y 17).

## Cadena organizativa

```
ASDE Scouts de España (federación, 17 organizaciones federadas)
└── ASDE Scouts de Balears (organización federada, 1991)
    └── Grupo Scout Myotragus 684 (s'Arenal de Llucmajor, desde 19/10/2010)
        ├── Consejo de Grupo (todos los scouters) · Coordinación de Grupo
        └── Secciones → cada una con su Kraal (equipo de scouters de sección)
```

## Secciones (nomenclatura estándar, la que usa ASDE Scouts de Balears)

| Slug propuesto | Sección (educandos) | Unidad | Edad | Color oficial | Marco simbólico | Pequeños grupos | Educadores |
|---|---|---|---|---|---|---|---|
| `castores` | Castores | Colonia | 6–8 | Azul celeste | "Amigos del bosque" | — | Grandes Castores |
| `lobatos` | Lobatos | Manada | 8–11 | Amarillo | La Selva de Seeonee (*El Libro de la Selva*) | Seisenas | Viejos Lobos (Akela, Baloo…) |
| `scouts` | Scouts | Sección Scout / Tropa | 11–14 | Verde | La Aventura, sistema de patrullas | Patrullas (con Guía) | Scouters de sección |
| `escultas` | Escultas (o Pioneros) | Unidad | 14–17 | Marrón | El Proyecto / La Empresa | Equipos / Comisiones | Scouters de sección |
| `rovers` | Rovers (o Compañeros) | Clan (o Comunidad) | 17–21 | Rojo | La Ruta por la vida | Comisiones / Talleres | Scouters de Clan |
| `scouters` | Scouters | — | +21 | — | — | Kraal / Consejo de Grupo | Voluntariado adulto |

Otros términos: el curso se llama **Ronda Solar**, y el uniforme común es un polo azul acero con pañoleta.

## Qué secciones tiene hoy Myotragus 684

**No se puede confirmar con fuentes públicas.** El artículo del 10.º aniversario en scout.es (2020) dice que el grupo abrió con Colonia, Manada y Scouts, y apunta a que después se abrieron Escultas y Rovers. **La lista real saldrá de las membresías del CRM**, no se escribirá a mano en el código.

## Implicaciones para el desarrollo

- **Las secciones son un catálogo, no una constante en el código.** Se guardan como lista configurable con slug, nombre, unidad, edades y color, precargada con la tabla de arriba. Así se pueden añadir "Pioneros/Compañeros" si otra organización federada o grupo usa esa nomenclatura.
- **Correspondencia con el CRM:** hay que averiguar cómo guarda el CRM la sección de cada membresía (lista desplegable, texto libre, proyecto…) y crear la tabla de equivalencias valor del CRM → slug. Ver [04](04-integracion-sinergiacrm.md) y la pregunta P-15.
- **Colores de sección en la interfaz:** sirven para etiquetas y badges en listados y fichas. Hay que comprobar el contraste: el amarillo y el azul celeste necesitan texto oscuro. Ver [06](06-tema-y-estilos.md).
- **Scouters:** es una "sección" de adultos a efectos de membresía. El scouter tiene, además, **asignación a una o varias secciones educativas** (su Kraal). Eso es lo que define su ámbito de permisos.
- **Coordinación** encaja con la figura de ASDE "Coordinador/a de Grupo". Puede que haya también coordinadores de sección (P-03).
