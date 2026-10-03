# App móvil (PWA) · se ha mudado

**Desde la v0.18.0 (2026-10-03) la app móvil es un plugin aparte, en su propio repo:**

- Repo: `C:\Proyectos\GestorCRM-App` (git, rama `main`; remoto privado `fmrondonPmi/GestorCRM-App`).
- Plugin: `asde-area-privada-app` («ASDE Scouts de España - App móvil del Gestor CRM»), que necesita este plugin 0.18.0 o posterior.
- Sus docs (estado, arquitectura, avisos push y **manual de publicación**) están en `docs/` de ese repo: empezar por `docs/README.md` y `docs/ESTADO.md`.

Aquí, en el Gestor CRM, queda solo:

- los **enganches genéricos** que usa la app: filtros `asde_ap_plantilla` (`Frontend\TemplateLoader`), `asde_ap_entorno` (`Install\Entorno`), `asde_ap_catalogo` y `asde_ap_textos` (`Idiomas\Traducciones`);
- **`Install\AppRetirada`**: si la app no está activa, `/asde-sw.js` sirve `assets/js/sw-retirada.js`, un service worker que borra las cachés de la app, da de baja los avisos y se da de baja (las apps ya instaladas no se quedan atascadas);
- la prueba `tests/app-enganches.php`;
- los apartados de la app en los **manuales de uso** (`manual/fuentes/instalar-app.html` y `admin-app.html`).

La historia de la PWA dentro de este plugin (v0.17.0–0.17.5) está en los commits `e5242d0`…`88e76a4` y en el índice (`docs/00-INDICE.md`).
