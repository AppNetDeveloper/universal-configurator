# Manual del MCP de Universal Configurator (para ADRI y otras IA)

> Este manual y las `instructions` que devuelve `initialize` dicen lo mismo: las instrucciones son su resumen corto.
> Si cambias una regla aquí, cámbiala también en `mcp_instructions()` (`app/mcp.php`).
> Los ejemplos de llamada y de respuesta están **copiados de una sesión real** (23-nov-2026, datos de ejemplo de la
> cocina), recortados donde lo indica «…».

## Qué es

Universal Configurator configura productos por catálogo (cocinas, muebles, armarios). El catálogo es un árbol de nodos
(Cocina → Modelo → Color). Una configuración parte de un **nodo final** con su composición por defecto: paredes con
módulos. Cada módulo tiene un tipo y un **ancho en cm con un decimal como mucho** (58,5). El alto y el fondo salen del
tipo. Toda medida de salida va también en **mm** (`width_mm`, `height_mm`, `depth_mm`).

La aplicación calcula **siempre** el precio y las piezas. **No los recalcules ni los inventes**: léelos de sus
respuestas.

## Conexión y permisos

- `POST https://<tu-dominio>/mcp`, JSON-RPC 2.0, sin estado. Cabecera `Authorization: Bearer uc_…`.
- El token se crea en **Panel → Tokens MCP** y actúa como la persona elegida. Si esa persona es comercial, solo ve y
  toca **sus** configuraciones; si es administradora, todas.
- **Ámbitos:**
  - `read`: `catalog_tree`, `module_types`, `config_list`, `config_get`, `price_check`, `cut_list`, `lead_list` y
    `lead_get`;
  - `write`: además `config_create`, `config_update`, `config_accept`, `config_share`, `config_mark` y `lead_mark`.
- **Aceptar es del equipo**: un token cuyo dueño es un cliente no acepta (403 con la salida buena). El cliente final
  pide el presupuesto desde el configurador (estado `requested`) y eso crea un lead.
  - Con un token `read`, las herramientas que escriben ni aparecen en `tools/list`.


> `config_accept` se publica como **irreversible** (`annotations.destructiveHint: true` y `_meta["mcpu/effects"]: ["irreversible"]`): una IA que respete esas marcas (ADRI lo hace) pide el «sí» de la persona antes de llamarla.

## Flujo recomendado

```
catalog_tree → module_types (node_id) → config_create → config_update → price_check / config_get → cut_list → config_accept
```

1. **`catalog_tree`**: busca el nodo final (`is_final: true`).
2. **`module_types`** con `node_id`: tipos, medidas por defecto, tramos que valen en ese nodo y fórmulas del despiece.
3. **`config_create`**: crea el borrador, con los datos del cliente si los tienes.
4. **`config_update`**: cambia anchos, quita, añade o sustituye módulos, y pone los datos del cliente. Las
   operaciones van **todas o ninguna**.
5. **`price_check`** para probar sin guardar; **`config_get`** para leer el resultado: precio, tramo, medidas y despiece.
6. **`cut_list`** para la lista de corte.
7. **`config_accept`**: **solo cuando la persona lo haya confirmado expresamente tras ver el total.** Acepta y congela
   el precio y el despiece; después ya no se edita (para cambiarla, `config_create` con `copy_of`).

## Trabajar lo nuevo (sin procesar)

Cada configuración aceptada y cada lead (un cliente que pidió el presupuesto) llevan un **estado de trabajo**,
aparte del estado de venta: `work_status` = `nuevo` → `en_curso` → `hecho`, con `work_note` (una línea, ≤ 200),
`work_by` (quién lo marcó: la persona o el nombre del token) y `work_at`. Nace «nuevo» al aceptar y al entrar un lead.

```
config_list(pendientes=true) → por cada una: config_get → haz lo tuyo (planos, presupuesto, lista de corte)
  → config_mark(code, work_status="hecho", work_note="Planos y presupuesto enviados")
lead_list(work_status="nuevo") → lead_get → llama o escribe → lead_mark(lead_id, work_status="hecho", estado="contactado", work_note="…")
```

`config_list` pone las pendientes primero y dice cuántas hay (`pending`). Marcar **no** toca el precio, el despiece ni
el estado de venta (y no cambia `updated_at`). Cada cambio queda en **Panel → Actividad**.

## Qué herramientas ESCRIBEN

| Escribe | Cuándo |
|---|---|
| `config_create` | Empezar una configuración (o duplicar una con `copy_of`). |
| `config_update` | Cambiar un borrador. |
| `config_accept` | **Confirmado por la persona.** Nunca por iniciativa propia. Solo con un token del equipo. |
| `config_share` | Mandar la configuración al cliente (enlace de solo lectura, 30 días). |
| `config_mark` | Has procesado una configuración: estado de trabajo y nota. |
| `lead_mark` | Has trabajado un lead: estado de trabajo, nota y estado comercial. |

Todo lo demás solo lee. Cada llamada queda en **Panel → Actividad**.

Límites por persona (de sobra para trabajar): `config_update` como mucho 600 llamadas cada 10 minutos y `config_share`
60 por hora. Si se pasan, la respuesta es un error 429 que dice cuánto esperar y no se ha hecho nada.

## Reglas de precio y de medida

- **Tramos:** cada tipo tiene tramos `[desde, hasta]` en cm, con los dos bordes incluidos y un precio fijo por tramo;
  **no se cobra por cm**. 58,5 cae en 51–60.
  - Si el ancho cae en un **hueco entre dos tramos** (p. ej. 50,5 con 41–50 y 51–60), se cobra el **tramo de arriba**
    (51–60): la línea trae `tier_rule: "next_up"` y `tier_note` («50,5 cm está entre tramos: se cobra como 51–60 cm.»).
    Dentro de un tramo, `tier_rule: "exact"`. No hay precio por debajo del primer tramo ni por encima del último.
- **Medidas no válidas:** un ancho por debajo del primer tramo, por encima del último o fuera del ancho mínimo y máximo
  del tipo no es válido. El error dice qué vale.
- **Tramos por nodo:** un nodo (p. ej. un modelo) puede tener tramos propios de un tipo, que **sustituyen** a los
  generales. `module_types` con `node_id` da los que valen (`effective_tiers`).
- **Total:** suma de líneas en **EUR sin IVA**. El IVA va aparte (`tax_rate`, `tax`, `total_with_tax`).
- **Despiece:** cada tipo tiene piezas con fórmulas.
  - Variables: `W`, `H` y `D` (medidas del módulo en mm), `T` (tablero, normalmente 19) y `B` (trasera, normalmente 8).
  - Las piezas salen en mm, **redondeadas a 0,5 mm**, con veta (`grain`: `largo` o `ancho`) y canto (`edge_banding`).

## Para dibujar en CAD

Usa **`config_get`** (medidas y despiece de cada módulo) o **`cut_list`** (piezas sumadas).

- **Pared:** trae `direction` (`x` o `y`: el eje por el que avanza) y su origen `origin_x_mm` / `origin_y_mm`.
- **Módulos:** van **en orden desde ese origen**, cada uno con `width_mm`, `height_mm` y `depth_mm`.
- **Altos y bajos:** las paredes «Altos …» comparten origen con su frente. La familia (`bajo`, `alto`, `columna`) dice a
  qué altura van.
- **Piezas:** `parts` de cada línea, con `qty`, `thickness_mm`, `length_mm` y `width_mm`. En `formulas` va la fórmula de
  cada medida, por si la quieres enseñar.
- **Quién dibuja:** la aplicación **no dibuja planos**: los dibujas tú con estos datos exactos.

## Cada herramienta

### `catalog_tree` (read)

**Cuándo:** lo primero, para encontrar el nodo final.

**Devuelve:** `tree`, cada nodo con `id`, `name`, `description`, `image` (URL o color `#RRGGBB`), `is_final` y
`children`.

### `module_types` (read)

**Cuándo:** antes de añadir o sustituir módulos, con `node_id`.

**Devuelve:** cada tipo con:
- medidas por defecto en mm, mínimo y máximo;
- `board_mm` (T) y `back_mm` (B);
- `tiers` (generales) y `node_tiers` (solo de ramas activas);
- `effective_tiers` (con `node_id`);
- `parts` (las fórmulas) y `parts_default` (las piezas con las medidas por defecto).

Llamada real: `module_types {"node_id": 5}`. Respuesta (un tipo, recortado):

```json
{"id": 3, "name": "Cajonera 3 cajones", "ref": "CAJ3", "family": "bajo",
 "width_mm": 600, "height_mm": 720, "depth_mm": 560, "min_width_mm": 400, "max_width_mm": 900,
 "board_mm": 19.0, "back_mm": 8.0,
 "tiers": [{"from_cm": 40, "to_cm": 50, "from_mm": 400, "to_mm": 500, "price": 160.0, "price_cents": 16000, "price_text": "160,00 €"}, …],
 "parts": [{"id": 13, "name": "Lateral", "qty": 2, "thickness": "T", "length_expr": "H", "width_expr": "D", "grain": "largo", "edge_banding": "1L"},
           {"id": 14, "name": "Base", "qty": 1, "thickness": "T", "length_expr": "W - 2*T", "width_expr": "D", "grain": "largo", "edge_banding": "1L"}, …],
 "effective_tiers": [ … ], "effective_from_node_id": null}
```

### `config_list` (read)

**Cuándo:** para buscar configuraciones.

**Filtros:**
- `status`: `draft`, `requested` (presupuesto pedido por el cliente), `accepted` o `cancelled`;
- `work_status`: `nuevo`, `en_curso` o `hecho`;
- `pendientes`: `true` = aceptadas con el trabajo `nuevo` o `en_curso` (lo que piden los clientes está en `lead_list`);
- `from` y `to`: fecha de creación, en formato `AAAA-MM-DD`;
- `q`: código, nombre, correo o teléfono;
- `page` y `per_page` (como mucho 100).

**Devuelve:** `items` (código, estado en palabras, producto, cliente, total en número y céntimos, estado de trabajo con
su nota y quién), `total`, `pages` y `pending` (cuántas quedan por trabajar). Las pendientes salen primero.

### `config_get` (read)

**Cuándo:** para leer una configuración entera, también para dibujarla.

**Devuelve:** nodo, cliente, estado (`status` y `status_label`), paredes con geometría y líneas.

Cada línea lleva:
- **medidas:** `width_cm`, `width_text` («58,5»), `width_mm`, `height_mm` y `depth_mm`;
- **tramo aplicado:** `tier_text` y `tier_from_mm` / `tier_to_mm`;
- **precio:** `price` en euros, `price_cents` y `price_text`;
- **piezas:** `parts`.

Además, el total sin IVA, el IVA y el total con IVA. En una configuración aceptada (`frozen: true`), precios y piezas
son la foto congelada.

Llamada real: `config_get {"code": "UC-5UN7DGA8"}`. Respuesta (una línea, recortada):

```json
{"code": "UC-5UN7DGA8", "status": "draft", "status_label": "Borrador",
 "node": {"id": 5, "path_text": "Cocina › Línea › Blanco"},
 "customer": {"name": "Marta Ruiz", "email": "marta@ejemplo.com", "phone": "600 111 222"},
 "walls": [{"id": 1, "name": "Frente", "direction": "x", "origin_x_mm": 0, "origin_y_mm": 0,
   "items": [{"id": 5, "type_name": "Cajonera 3 cajones", "ref": "CAJ3", "family": "bajo",
     "width_mm": 585, "height_mm": 720, "depth_mm": 560, "width_cm": 58.5, "width_text": "58,5",
     "valid": true, "tier_text": "51–60 cm", "tier_from_mm": 510, "tier_to_mm": 600,
     "price": 185.0, "price_cents": 18500, "price_text": "185,00 €",
     "parts": [{"name": "Lateral", "qty": 2, "thickness_mm": 19.0, "length_mm": 720.0, "width_mm": 560.0, "grain": "largo", "edge_banding": "1L",
                "formulas": {"thickness": "T", "length": "H", "width": "D"}}, …]}, …]}, …],
 "total": 1260.0, "total_text": "1.260,00 €", "tax_rate": 21.0, "tax": 264.6, "total_with_tax": 1524.6}
```

El despiece completo de esa cajonera de 58,5 cm, sacado de la misma respuesta:

| Pieza | Cant. | Espesor | Largo × ancho (mm) | Fórmulas |
|---|---|---|---|---|
| Lateral | 2 | 19 | 720 × 560 | `H` × `D` |
| Base | 1 | 19 | 547 × 560 | `W - 2*T` × `D` |
| Travesaño | 2 | 19 | 547 × 100 | `W - 2*T` × `100` |
| Trasera | 1 | 8 | 717 × 582 | `H - 3` × `W - 3` |
| Frente de cajón | 3 | 19 | 581 × 236 | `W - 4` × `(H - 12) / 3` |
| Lateral de cajón | 6 | 16 | 500 × 150 | `D - 60` × `150` |
| Frente y trasera de caja | 6 | 16 | 489 × 150 | `W - 2*T - 58` × `150` |
| Fondo de cajón | 3 | 8 | 498 × 521 | `D - 62` × `W - 2*T - 26` |

### `price_check` (read)

**Cuándo:** para probar precios y medidas **sin guardar**.

Llamada real:

```
price_check {"node_id": 5, "modules": [{"module_type_id": 3, "width_cm": "58,5"}, {"module_type_id": 3, "width_cm": 40.5}]}
```

**Devuelve:**
- por línea: `valid`, `price_text`, `tier_from_cm`, `tier_to_cm`, `tier_rule` y `tier_note`, o `error` con los
  `tiers` posibles;
- en total: `total`, `total_text`, `tax`, `total_with_tax` y `saved: false`.

En esa sesión, 58,5 dio 185,00 € (tramo 51–60) y 40,5 dio 160,00 € (tramo 40–50). Con la regla del tramo de arriba,
50,5 en la cajonera (tramos 40–50 y 51–60) da 185,00 € con `tier_rule: "next_up"`. Llamada real (23-nov):

```
price_check {"node_id": 5, "modules": [{"module_type_id": 3, "width_cm": "50,5"}, {"module_type_id": 3, "width_cm": 50}, {"module_type_id": 3, "width_cm": 95}]}
```

```json
{"node": "Cocina › Línea › Blanco", "total_text": "345,00 €", "valid": false,
 "lines": [
  {"width_cm": 50.5, "valid": true, "tier_from_cm": 51, "tier_to_cm": 60, "tier_rule": "next_up",
   "tier_note": "50,5 cm está entre tramos: se cobra como 51–60 cm.", "price_text": "185,00 €", "error": null},
  {"width_cm": 50, "valid": true, "tier_from_cm": 40, "tier_to_cm": 50, "tier_rule": "exact", "tier_note": null, "price_text": "160,00 €", "error": null},
  {"width_cm": 95, "valid": false, "tier_rule": null, "price_text": "—", "error": "95 cm está fuera del ancho de «Cajonera 3 cajones»: entre 40 y 90 cm."}]}
```

### `cut_list` (read)

**Cuándo:** para fabricar o dibujar las piezas.

**Devuelve:** la lista de corte agregada. Las piezas iguales (mismo espesor, largo, ancho, veta y canto) se suman, y
cada fila dice de qué módulos sale (`from`). También devuelve `pieces` (el total de piezas), `status_label` y `frozen`.

Fila real de `cut_list {"code": "UC-5UN7DGA8"}`:

```json
{"name": "Frente de cajón", "qty": 3, "thickness_mm": 19.0, "length_mm": 581.0, "width_mm": 236.0, "grain": "largo", "edge_banding": "4L",
 "from": [{"item_id": 5, "module": "Cajonera 3 cajones", "wall": "Frente", "width_cm": 58.5, "qty": 3}]}
```

### `config_create` (write)

**Cuándo:** para empezar una configuración. Admite tres formas:
- desde un nodo final (`node_id`), con su composición por defecto;
- con `customer` (`name`, `email`, `phone`);
- `copy_of`, que duplica cualquier configuración, también una aceptada, en un borrador nuevo con los precios de hoy.

Llamada real:

```
config_create {"node_id": 5, "customer": {"name": "Marta Ruiz", "email": "marta@ejemplo.com", "phone": "600 111 222"}}
```

**Devuelve:** `config`, como `config_get`. En esa sesión, `UC-5UN7DGA8`: 9 módulos y 1.260,00 €.

### `config_update` (write)

**Cuándo:** para cambiar un borrador.

**Operaciones** (en `ops`, en orden; se aplican **todas o ninguna**):
- `set_width {item_id, width_cm}`; `width_cm` admite 58.5 o "58,5", y `width_mm` es la alternativa en mm;
- `remove {item_id}`;
- `add {wall_id, module_type_id, width_cm?}`; sin ancho, usa uno válido;
- `replace {item_id, module_type_id, width_cm?}`; sin ancho, conserva el que tenía si vale o pone el del tramo más
  cercano, y lo dice en `notes`;
- `customer {name?, email?, phone?, notes?}`.

Llamada real:

```
config_update {"code": "UC-5UN7DGA8", "ops": [{"op": "set_width", "item_id": 5, "width_cm": 58.5}]}
```

**Devuelve:** la configuración con el total y el despiece nuevos, y `notes`.

### `config_accept` (write)

**Cuándo:** **solo** cuando la persona haya dicho que acepta, tras ver el total. Acepta y congela el precio y el
despiece.

Respuesta real (`note`):

> Aceptada y con el precio y el despiece congelados. Confirma a la persona el código y el total; para cambiarla habrá
> que duplicarla.

### `config_share` (write)

**Cuándo:** para mandar una configuración al cliente. **Devuelve:** `url` (solo lectura, firmada, caduca en 30 días,
sin datos personales ni en la URL ni en la página; se construye con la dirección pública de la instalación),
`expires_at`, `days` y `whatsapp_url` (con el texto listo). Compartir otra vez el mismo día da el mismo enlace (caduca
al final del día 30). Una anulada no se comparte y su enlace deja de abrir.

### `config_mark` (write)

**Cuándo:** al terminar (o empezar) lo tuyo con una configuración aceptada (las pedidas, por su lead). **Parámetros:** `code`,
`work_status` (`nuevo`, `en_curso`, `hecho`) y `work_note` (opcional, una línea, ≤ 200). Un comercial solo marca las
suyas. **Devuelve:** la configuración con `work_status`, `work_note`, `work_by` y `work_at`.

### `lead_list` (read)

**Cuándo:** para ver quién ha pedido presupuesto desde el configurador. **Filtros:** `estado` (`nuevo`, `contactado`,
`cerrado`), `work_status`, `q` (nombre, teléfono o correo), `page`, `per_page`. Un admin ve todos; un comercial, los de
sus configuraciones. **Devuelve:** `items` (nombre, teléfono, correo, mensaje, código y total de su configuración,
estados), `total`, `pages` y `pending`.

### `lead_get` (read)

**Cuándo:** antes de llamar o escribir. **Parámetro:** `lead_id`. **Devuelve:** el lead entero (con el consentimiento
y su `configuration_code`; los módulos, con `config_get`).

### `lead_mark` (write)

**Cuándo:** al trabajar un lead. **Parámetros:** `lead_id`, `work_status`, `work_note` (opcional) y `estado`
(opcional: `nuevo`, `contactado`, `cerrado`).

## Errores típicos y cómo leerlos

Todo error de herramienta llega con `isError: true` y un texto con cuatro partes:
1. qué falló, con el valor que mandaste;
2. qué vale;
3. un ejemplo de llamada;
4. «No se ha hecho nada».

Los errores reales de la sesión:

- **Ancho fuera del tipo:**
  > Error: 95 cm está fuera del ancho de «Cajonera 3 cajones»: entre 40 y 90 cm. | Detalle: {"tiers": [{"from_cm": 40,
  > "to_cm": 50, …}], "item_id": 5, "width_cm": 95, "width_mm": 950, "min_width_mm": 400, "max_width_mm": 900} | Ejemplo
  > de llamada: config_update {…} | No se ha hecho nada.
- **Parámetro que no existe** (nunca se ignora en silencio):
  > Error: config_create: el campo «cliente» no existe. Valen: node_id, copy_of, customer. | Ejemplo de llamada:
  > config_create {"node_id":12,"customer":{…}} | No se ha hecho nada.
- **Cambiar una aceptada:**
  > Error: La configuración UC-5UN7DGA8 está aceptada: ya no se edita. Duplícala para hacer cambios (botón «Duplicar» o
  > config_create con copy_of). | … | No se ha hecho nada.

Otros:

| Error | Qué significa |
|---|---|
| «no es una medida válida … Tramos posibles: …» | El ancho no cae en ningún tramo. Elige uno de los que lista. |
| «No existe la configuración … o no tienes acceso» | No existe, o es de otra persona y tu token no la ve. |
| «Este token no tiene el permiso 'write'» | Pide un token con `write`. |
| «no es un nodo final: elige uno de sus hijos: …» | Baja un nivel más en `catalog_tree`. |
| «hay piezas que no se pueden fabricar con esas medidas» | Con ese ancho, alguna fórmula da ≤ 0 mm. Cambia el ancho. |
| HTTP 401 | Token que no existe, revocado o de una persona desactivada. |

## Respuestas «masticadas»

- **Importes:** en número (`price`, en euros), en céntimos (`price_cents`) y como texto (`price_text`: «185,00 €»).
- **Tramo aplicado:** `tier_text`, por ejemplo «51–60 cm», y `tier_rule` («exact» o «next_up») con su `tier_note`.
- **Medidas:** en cm (`width_cm`, `width_text`) y en mm (`*_mm`).
- **Estado:** en palabras (`status_label`: «Borrador», «Aceptada», «Anulada»).
- **Piezas:** calculadas por la aplicación, con la fórmula de cada una.
