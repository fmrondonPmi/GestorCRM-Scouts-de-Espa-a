# Gestor de menús de eventos – Base de conocimiento

> Funcionalidad del **generador de menús** del GestorCRM Scouts de España: recetas, menús de eventos y validación de alérgenos e intolerancias de los asistentes.
>
> **Estado:** borrador funcional basado en la descripción del módulo. Los nombres exactos de tablas, campos y roles del repositorio se marcan como **[PENDIENTE DE REPO]** hasta poder consultarlo.

## 1. Objetivo

Permitir que un scouter prepare el menú de comidas de un evento (acampada o campamento) a partir de **recetas dadas de alta**, y que el sistema **avise automáticamente** si algún asistente tiene un alérgeno o intolerancia alimentaria incompatible con las recetas elegidas.

El módulo conecta tres partes de la aplicación:

| Módulo | Aporta |
|---|---|
| **Eventos** | El evento, sus fechas, días y la lista de personas que asisten. |
| **Fichas de las personas (salud)** | Alergias, intolerancias y otras restricciones alimentarias. |
| **Menús y recetas** | Recetas con ingredientes y porciones; menú del evento. |

## 2. Conceptos

- **Receta**: plato con nombre, ingredientes, cantidades y número de porciones base (por ejemplo, "para 10 personas").
- **Ingrediente**: producto con cantidad y unidad. Cada ingrediente puede llevar asociados uno o varios **alérgenos**.
- **Porciones base**: número de personas para las que está escrita la receta. Sirve para escalar las cantidades.
- **Menú del evento**: conjunto de comidas del evento, organizado por día y tipo de comida (desayuno, comida, merienda, cena).
- **Asistente**: persona inscrita en el evento (educandos y scouters).
- **Alérgeno**: sustancia que provoca una reacción alérgica.
- **Intolerancia alimentaria**: reacción adversa a un alimento sin mecanismo alérgico (por ejemplo, lactosa).
- **Restricción dietética**: decisión o creencia (vegetariano, vegano, sin cerdo…). No es dato de salud, pero conviene tenerla en cuenta.

## 3. Catálogo de alérgenos

Se recomienda usar como base los **14 alérgenos de declaración obligatoria** de la normativa europea de información alimentaria (Reglamento (UE) 1169/2011):

1. Cereales con gluten (trigo, centeno, cebada, avena, espelta, kamut)
2. Crustáceos
3. Huevos
4. Pescado
5. Cacahuetes
6. Soja
7. Leche y lácteos (incluida la lactosa)
8. Frutos de cáscara (almendras, avellanas, nueces, anacardos, pistachos…)
9. Apio
10. Mostaza
11. Granos de sésamo
12. Dióxido de azufre y sulfitos
13. Altramuces
14. Moluscos

Intolerancias y otras restricciones habituales que conviene poder registrar: **lactosa, gluten (celiaquía), fructosa, histamina**, y restricciones dietéticas como **vegetariano, vegano**.

> La lactosa es una intolerancia asociada al alérgeno "leche y lácteos". El gluten se asocia a "cereales con gluten". El sistema debe poder relacionar cada intolerancia con uno o varios alérgenos del catálogo para poder comparar.

## 4. Alta de una receta

### Datos de la receta

- Nombre
- Tipo de plato / comida (desayuno, comida, merienda, cena, postre…)
- **Porciones base** (para cuántas personas está escrita)
- Elaboración (pasos), opcional
- Observaciones (por ejemplo, "apta para celíacos")
- **Listado de ingredientes**

### Datos de cada ingrediente

- Nombre
- Cantidad y unidad (g, kg, ml, l, unidades, cucharadas…)
- **Alérgenos que contiene** (selección del catálogo)
- **Trazas** ("puede contener"), opcional
- Coste unitario, opcional (permitiría estimar el coste por persona y día)

### Reglas importantes

- Los alérgenos se registran **en el ingrediente**. La receta **hereda** automáticamente los alérgenos de todos sus ingredientes. Así se evitan olvidos al editar una receta.
- Un mismo ingrediente se reutiliza en distintas recetas con los mismos alérgenos.
- Si se modifica el ingrediente o la receta, deben **revalidarse** los menús que la usan.

### Escalado de cantidades

Para cocinar para un número distinto de personas:

> **Cantidad necesaria = cantidad de la receta × (comensales ÷ porciones base)**

Ejemplo: una receta de lentejas para 10 personas lleva 1,5 kg de lentejas. Para un evento con 42 comensales: 1,5 × (42 ÷ 10) = **6,3 kg**.

## 5. Menú del evento

1. El scouter abre el evento y entra en su menú.
2. Elige el **día** y el **tipo de comida**.
3. **Selecciona una receta** (o varias) de las dadas de alta.
4. El sistema obtiene los **comensales** (personas que asisten al evento) y escala los ingredientes.
5. El sistema **valida alérgenos e intolerancias** (sección 6) y muestra el resultado.
6. El scouter ajusta (cambia receta, añade alternativa) hasta que no queden conflictos sin resolver.

Con el menú completo se puede obtener la **lista de la compra** sumando los ingredientes escalados de todas las recetas.

## 6. Validación de alérgenos e intolerancias

### Cuándo se ejecuta

- Al **asociar una receta** a una comida del menú.
- Al **cambiar la lista de asistentes** (alta o baja de un inscrito).
- Al **modificar la ficha de salud** de un asistente.
- Al **modificar una receta o un ingrediente** ya usado en un menú.

### Cómo funciona

1. Obtener los **alérgenos de la receta** (unión de los de sus ingredientes).
2. Obtener la **lista de asistentes** del evento.
3. Para cada asistente, leer su **ficha de salud**: alergias, intolerancias y restricciones.
4. Convertir las intolerancias a alérgenos del catálogo (lactosa → leche; gluten → cereales con gluten).
5. **Comparar**: ¿algún alérgeno de la receta coincide con los del asistente?
6. Registrar cada coincidencia: persona, alérgeno, **ingrediente causante** y receta.

### Niveles de aviso

| Nivel | Cuándo | Qué se muestra |
|---|---|---|
| **Conflicto** | La receta contiene un ingrediente con un alérgeno que afecta a un asistente. | Aviso destacado (rojo). |
| **Posible riesgo** | Solo hay trazas ("puede contener") del alérgeno. | Aviso de precaución (ámbar). |
| **Sin conflictos** | Ningún asistente se ve afectado. | Confirmación (verde). |
| **Datos incompletos** | Un asistente no tiene la ficha de salud rellenada. | Aviso para completar la ficha. |

### Qué debe indicar el aviso

- **Cuántas personas** se ven afectadas, por alérgeno.
- **Quiénes** (solo para usuarios autorizados, ver sección 7).
- **Qué ingrediente** causa el conflicto.
- **Alternativas**: sustituir el ingrediente, elegir otra receta o preparar una ración aparte.

### Resolución

El scouter puede resolver cada conflicto de varias formas: cambiar la receta, marcar que se preparará una **alternativa** para las personas afectadas, o confirmar el aviso dejando constancia (quién lo confirma y cuándo).

## 7. Privacidad y datos de salud

Las alergias e intolerancias son **datos de salud**, categoría especial de datos personales (art. 9 del RGPD). Muchos asistentes son **menores**. Recomendaciones:

- Mostrar el **detalle por persona** solo a los roles autorizados.
- En documentos para cocina o intendencia (menú impreso, lista de la compra), mostrar **solo el alérgeno y el número de personas**, sin nombres ni diagnósticos, salvo que sea imprescindible.
- Guardar únicamente lo necesario: el alérgeno o intolerancia, no el historial médico.
- Dejar **registro** de quién consulta o confirma avisos.

## 8. Relación con la planificación del evento

Según el organizador de acampadas de ASDE, el menú forma parte del calendario del evento:

| Tarea | Acampada | Campamento |
|---|---|---|
| Elaborar menú (revisar alergias típicas del grupo) | 18 días antes | 35 días antes |
| Estimar presupuesto de comidas | 18 días antes | 35 días antes |
| Comprobar scouters con titulación de manipulador de alimentos | 18 días antes | 35 días antes |
| Revisar alergias médicas de asistentes, medicinas y menú | 8 días antes | 15 días antes |
| Comprar comida y embalarla | 3 días antes | 8 días antes |

**Presupuesto de comidas** = (educandos + scouters) × precio por acampado y día × número de días.

Además, hay que elegir **cocinero, intendente y ayudantes** (20 días antes en acampadas, 40 en campamentos).

## 9. Ejemplos

**Ejemplo 1 – Conflicto.** Se asocia "Macarrones con tomate" a la comida del sábado. La receta contiene *pasta de trigo* (cereales con gluten). Hay 2 asistentes con celiaquía. El sistema muestra: *"Conflicto: 2 personas afectadas por cereales con gluten. Ingrediente: macarrones."* Propone usar pasta sin gluten para esas raciones.

**Ejemplo 2 – Intolerancia a la lactosa.** Se asocia "Natillas". Contienen leche y huevo. Un asistente es intolerante a la lactosa (→ leche). El sistema avisa por *leche y lácteos*. El huevo no genera aviso porque ningún asistente es alérgico al huevo.

**Ejemplo 3 – Ficha sin rellenar.** Tres inscritos no tienen la ficha de salud completa. El sistema avisa "Datos incompletos: 3 asistentes" para que se completen antes de cerrar el menú.

**Ejemplo 4 – Un asistente se da de baja.** Era el único alérgico al pescado. El sistema revalida y el aviso de la receta "Merluza al horno" desaparece.

## 10. Preguntas frecuentes

**¿Dónde se indican los alérgenos, en la receta o en el ingrediente?** En el ingrediente. La receta los hereda.

**¿Qué pasa si añado una receta y alguien tiene alergia?** El sistema muestra un aviso con el alérgeno, el ingrediente causante y el número de personas afectadas.

**¿Quién puede ver quién tiene la alergia?** Solo los roles autorizados. El resto ve el alérgeno y el número de personas.

**¿Se recalcula si cambian los asistentes?** Sí, se revalida cuando cambia la lista de asistentes, una ficha de salud o una receta.

**¿Cómo se ajustan las cantidades a los asistentes?** Cantidad × (comensales ÷ porciones base).

**¿Y si el alérgeno solo aparece como "puede contener"?** Se muestra como "posible riesgo", con precaución.

**¿Se bloquea el menú si hay conflicto?** Recomendado: no bloquear, pero exigir resolver o confirmar cada aviso con constancia.

## 11. Integración con GestorCRM [PENDIENTE DE REPO]

El repositorio indicado devolvió un error 404 al intentar abrirlo (puede ser privado o la dirección puede ser distinta). Para completar esta base de conocimiento hace falta conocer:

- **Evento**: cómo se guarda y cómo se obtiene la lista de inscritos.
- **Persona / asistente**: cómo se relaciona con el evento.
- **Ficha de salud**: cómo se guardan alergias e intolerancias, **si en texto libre o con un catálogo**. Es el dato más crítico: si es texto libre, hay que normalizarlo para poder compararlo con los alérgenos.
- **Roles y permisos**: quién ve datos de salud.
- **Tecnología**: lenguaje, base de datos y convenciones.
