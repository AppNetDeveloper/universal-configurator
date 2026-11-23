# Universal Configurator — diseño (v0.3)

> Contrato para quien lo construye. Hermano de **Universal Web** (`/var/www/universal-web`): mismo estilo (PHP puro, sin
> frameworks, sin Composer, sin Node, SQLite, panel propio, MCP propio, instalador), misma licencia (Licencia BionicAdri
> 2.0 con permiso expreso de usarlo con los clientes propios). Copia sus patrones; no reinventes lo que ya resolvió
> (`app/core.php`: `db()`, `q()`, `row()`, `rows()`, `insert()`, `update()`, `migrate()`, `throttle()`, `e()`,
> `json_response()`; `app/security.php`: cabeceras y sesión; `app/mcp.php`: JSON-RPC 2.0 sin estado por POST a `/mcp`).

## Qué es

Una aplicación web para que un fabricante o una tienda (cocinas, muebles, armarios…) configure un producto a partir de
su catálogo y obtenga el precio al momento:

1. El ADMIN carga un **catálogo en árbol** genérico (padre → hijo → hijo del hijo, sin límite de niveles; p. ej.
   Cocina → Modelo «Línea» → Color «Roble»), los **tipos de módulo** (Bajo 1 puerta, Cajonera, Fregadero…) con su
   **tabla de precios por tramos de medida** (de 41 a 60 cm = X €; de 61 a 80 cm = Y €; NO se cobra por centímetro),
   y los **módulos por defecto** de cada nodo final (la composición con la que arranca una cocina de ese modelo).
2. Un COMERCIAL o un CLIENTE (o un invitado, si el admin lo permite) elige en la tablet nodo a nodo hasta un nodo
   final; aparecen sus módulos por defecto repartidos por paredes; puede cambiar la medida de cada módulo (cm enteros),
   quitarlo, sustituirlo por otro tipo o añadir; el precio total se recalcula al momento; al final acepta y se guarda.
3. El ADMIN ve todas las configuraciones y las descarga (JSON y CSV; PDF sencillo opcional).
4. **ADRI** (u otra IA) se conecta por **MCP** y lee/cambia configuraciones con sus medidas exactas para hacer lo
   pesado fuera de la app: plano CAD acotado, PDF profesional, seguimiento. **La app NO dibuja planos.**

Fuera de esta versión: 3D, pago online, integración con ERP, varios idiomas (solo español; deja los textos en un único
fichero para traducir después), importar desde Excel.

## Reglas de negocio (una verdad, en el servidor)

- Ancho del módulo en **cm con un decimal como mucho** (58,5). Todas las medidas se guardan en **mm enteros**
  (58,5 cm = 585 mm). El alto y el fondo de cada línea salen del tipo de módulo (columnas `height_mm`/`depth_mm` de la
  línea preparadas para que en el futuro cada línea lleve los suyos).
- **Precio de un módulo** = el del tramo cuyo `[desde, hasta]` (ambos incluidos; bordes con un decimal en cm, guardados
  en mm) contiene su ancho: 58,5 cae en 41–60. Si el ancho cae en un **hueco entre dos tramos** se cobra el **tramo de
  arriba** (el primero cuyo «desde» es mayor que el ancho), nunca uno inferior ni «sin precio»: con 41–50 y 51–60,
  50,5 cm se cobra como 51–60; la línea lo dice (`tier_rule: "next_up"` y una nota en llano) y el panel lo explica en
  cada hueco. Sigue sin ser válido lo que queda por debajo del primer tramo, por encima del último o fuera del
  mínimo/máximo del tipo. Si ningún
  tramo lo contiene → la medida NO es válida y se rechaza diciendo qué tramos hay. Los tramos de un tipo no se solapan
  (validar al guardar). Opcional: tramos específicos de un nodo (modelo/color) que pisan a los generales del tipo.
- **Total** = suma de módulos. Moneda EUR. Precios SIN IVA; el IVA (porcentaje en ajustes, por defecto 21) solo se
  MUESTRA aparte. El cálculo vive en UNA función PHP (`precio_configuracion()`), la usan la tablet (vía API), el panel
  y el MCP. Nunca se calcula en JavaScript.
- Al **aceptar** se congela una foto del precio (tramo y precio de cada línea) **y del despiece** en la configuración:
  si luego cambian la tabla o las fórmulas, lo aceptado no cambia. Una configuración aceptada ya no se edita (se duplica).
- **Despiece** (v0.2): cada tipo tiene piezas con fórmulas en W, H, D (medidas del módulo en mm), T (tablero, 19 por
  defecto) y B (trasera, 8 por defecto): números, + - * / y paréntesis, con un intérprete propio (nunca `eval`), validadas
  al guardar. `piezas_de_linea()` es la única función que las calcula (API, panel, MCP, congelación), redondeando a
  0,5 mm. La lista de corte suma las piezas iguales (espesor, largo, ancho, veta y canto) con su módulo de origen.

## Datos (SQLite, `data/configurator.sqlite`, fuera de `public/`)

```
users(id, email UNIQUE, name, password_hash NULL, role CHECK(role IN('admin','comercial','cliente')), active,
      auth_source CHECK(auth_source IN('local','bionicadri')) DEFAULT 'local', external_id NULL, created_at, last_login_at)
password_resets(token_hash PK, user_id, expires_at)
nodes(id, parent_id NULL → nodes, name, slug, sort, description, image NULL, active)          -- árbol genérico
module_types(id, name, ref, description, family, width_mm, height_mm, depth_mm, min_width_mm NULL, max_width_mm NULL,
      board_mm DEFAULT 19 (T), back_mm DEFAULT 8 (B), active, sort)
module_parts(id, module_type_id, name, qty, thickness_expr, length_expr, width_expr, grain, edge_banding, sort)
price_tiers(id, module_type_id, node_id NULL, from_mm, to_mm, price, CHECK(from_mm <= to_mm))      -- price en céntimos
layout_walls(id, node_id, name, direction CHECK(direction IN('x','y')), origin_x_mm, origin_y_mm, sort)
default_modules(id, layout_wall_id, module_type_id, width_mm, sort)
configurations(id, code UNIQUE, node_id, user_id NULL, customer_name, customer_email, customer_phone,
      status CHECK(status IN('draft','requested','accepted','cancelled')), total, tax_rate, notes, created_at, updated_at,
      accepted_at, requested_at, work_status NULL CHECK(work_status IN('nuevo','en_curso','hecho')), work_note, work_by, work_at)
leads(id, configuration_id NULL, user_id NULL (el comercial de la configuración), name, email, phone, message, source,
      consent_at, status CHECK(status IN('nuevo','contactado','cerrado')), work_status, work_note, work_by, work_at,
      created_at, updated_at)
config_walls(id, configuration_id, name, direction, origin_x_mm, origin_y_mm, sort)
config_items(id, config_wall_id, module_type_id, width_mm, height_mm, depth_mm, sort, tier_from_mm, tier_to_mm, price)  -- tier/price: la foto
config_item_parts(id, config_item_id, name, qty, thickness_mm, length_mm, width_mm, grain, edge_banding, sort)    -- despiece congelado
settings(key PK, value)            -- empresa, logo, iva, permitir_registro, permitir_invitado, bionicadri_* (SSO)
mcp_tokens(id, name, token_hash UNIQUE, prefix, scopes, user_id (actúa como esa persona), created_at, last_used_at, revoked_at)
audit_log(id, at, actor, action, detail)
```

`direction` y `origin_*` de las paredes dan la geometría para que ADRI dibuje (pared que avanza en X o en Y desde su
origen, en mm). En la tablet basta con ver los módulos agrupados por pared, en orden.

## Acceso (simple, preparado para dos modos)

- **Local** (por defecto): correo + contraseña (`password_hash`/`password_verify`), sesión con cookie `HttpOnly`,
  `Secure` si HTTPS, `SameSite=Lax`; CSRF en todo POST; `throttle()` en el login (por IP y por correo); «olvidé mi
  contraseña» con token aleatorio guardado como hash y caducidad de 1 h (correo con `mail()` o SMTP de ajustes).
- El ADMIN da de alta comerciales (le llega el enlace para poner contraseña). Clientes: registro libre si
  `permitir_registro`; sin registro si `permitir_invitado` (deja nombre, correo y teléfono al aceptar, con casilla de
  consentimiento de protección de datos).
- **BionicEye (fase 2, deja el hueco hecho)**: interfaz de proveedor (`auth_providers.php`) con `local` implementado y
  `bionicadri` como OAuth2 Authorization Code + PKCE contra el BionicEye del cliente (Laravel Passport). Si
  `bionicadri_url` y `bionicadri_client_id` están en ajustes aparece «Entrar con BionicEye»; el usuario se crea/enlaza
  por `external_id` con `auth_source='bionicadri'` y sin contraseña local. En v0.1 basta la interfaz y el botón oculto.
- Roles: `admin` todo; `comercial` configura y ve las suyas; `cliente` configura y ve las suyas.

## Pantallas

Tablet/móvil primero (táctil, botones grandes, sin hover), funciona en escritorio. Identidad: sobria y seria (blanco y
grises, acento índigo `#4F46E5`, superficies planas). Ver la demo de referencia `docs/demo_referencia.html`.

- `/` entrar · `/registro` (si se permite) · `/olvide` · `/restablecer`
- `/configurar` paso 1: elegir nodo a nodo (tarjetas) hasta un nodo final → crea borrador con sus módulos por defecto.
- `/configurar/{code}` paso 2: paredes con sus módulos (tipo, ancho en cm con − / + y campo, quitar, sustituir, añadir),
  precio por línea y total en una barra fija abajo; medida no válida = aviso en la línea con los tramos posibles.
- `/configurar/{code}/resumen` paso 3: resumen, total sin IVA, IVA aparte, aceptar (invitado: sus datos + consentimiento).
- `/mis-configuraciones`
- `/admin` catálogo (árbol con crear/editar/ordenar/activar), tipos de módulo y sus tramos, composición por defecto de
  cada nodo final (paredes y módulos), configuraciones (lista con filtros, ver, descargar JSON/CSV), usuarios, ajustes,
  tokens MCP (crear → el token se ve UNA vez; revocar), registro de actividad.
- `/api/*` JSON para la tablet (sesión + CSRF): cada cambio llama al servidor y pinta lo que devuelve.

## MCP (`POST /mcp`, JSON-RPC 2.0, sin estado; token `Authorization: Bearer uc_…`, guardado como hash)

Ámbitos: `read`, `write`. Herramientas:

| Herramienta | Ámbito | Qué hace |
|---|---|---|
| `catalog_tree` | read | Árbol de nodos activos (id, nombre, hijos, si es final) |
| `module_types` | read | Tipos con su fondo y sus tramos (generales y por nodo) |
| `config_list` | read | Configuraciones con filtros (estado, desde, hasta, texto), paginadas |
| `config_get` | read | Una configuración entera: nodo, cliente, paredes con geometría (mm) y módulos (cm), tramo y precio de cada línea, total, estado |
| `price_check` | read | Precio de una lista de módulos para un nodo, sin guardar (y qué medida no es válida) |
| `config_create` | write | Borrador desde un nodo final con su composición por defecto |
| `config_update` | write | Cambios en un borrador: `set_width`, `remove`, `add`, `replace`, `customer`; devuelve la configuración y el total nuevo |
| `config_accept` | write | Acepta un borrador (congela precios y despiece). Devuelve el aviso «la aceptación la debe confirmar la persona» en la descripción |
| `cut_list` | read | Lista de corte agregada de una configuración (piezas iguales sumadas, con su módulo de origen) |

v0.2: `config_get` da en cada línea `width_mm`, `height_mm`, `depth_mm` y `parts` (el despiece calculado);
`module_types` da las medidas por defecto y las fórmulas; `config_create` admite `customer` (name, email, phone).
Un parámetro que una herramienta no declara es un error (dice cuál sobra y cuáles valen), nunca se ignora.
Manual completo para la IA: `docs/MCP.md`; las `instructions` de `initialize` son su resumen.

Errores del MCP «smart»: qué falló (con el valor recibido), qué vale, un ejemplo de llamada y «no se ha hecho nada».
`initialize` devuelve `instructions` con el flujo recomendado (como Universal Web).

## Estructura

```
public/index.php  public/assets/{app.css, app.js}
app/{bootstrap.php, core.php, security.php, auth.php, auth_providers.php, catalog.php, pricing.php,
     configurations.php, admin.php, api.php, mcp.php, install.php, mail.php, views/*.php}
data/  (no servido; .htaccess deny)  tests/*.php  deploy/{nginx,apache,caddy}.example  install.sh  LICENSE  README.md
```

`php -S` para desarrollar. Instalador web en el primer arranque (crea `data/config.php`, la base y el admin; pide la
URL pública y un código de un solo uso que deja en `data/install-code.txt`) y botón
«cargar datos de ejemplo» (una cocina con 3 modelos, 4 colores, ~9 tipos de módulo con tramos y composiciones).

## Calidad

- Pruebas en `tests/*.php` ejecutables con `php tests/<x>.php` (sin PHPUnit, como Universal Web): motor de precios
  (bordes de tramo, sin tramo, solapes, tramo por nodo), acceso (login, throttle, CSRF, roles, reset caducado), MCP
  (token, ámbitos, cada herramienta, errores), API (un comercial no ve configuraciones de otro).
- Todo SQL con parámetros; toda salida HTML con `e()`; ninguna ruta fuera de `public/` servida.
- Desarrollo y pruebas en Docker aislado: `docker run --rm -v /var/www/universal-configurator:/app -w /app
  wordpress:cli-php8.2 php tests/x.php` (el PHP del host no tiene SQLite y NO se toca).

## Seguridad (revisión del 23-nov)

- Escrituras con `BEGIN IMMEDIATE`; lo que cambia una configuración vuelve a leer su estado dentro de la transacción.
- Login: límites por IP (IPv6 por /64), por correo+IP, espera progresiva por cuenta+IP (1, 2, 4… s, tope 60) y 50 fallos
  por cuenta y hora; nada duerme (429 al momento). La cookie firmada de dispositivo de confianza salta los límites por
  cuenta. Hash de relleno precalculado (mismo tiempo exista o no el correo).
- Cambiar la contraseña o desactivar cierra las demás sesiones (`users.session_version`).
- Registro libre: solo nombre y correo, enlace de verificación de 24 h, mensaje genérico y tiempo mínimo uniforme;
  sin texto del usuario en los correos hacia un correo no verificado.
- Los enlaces externos usan solo la URL pública guardada (nunca la cabecera `Host`).

## v0.3: el cliente final, los leads, Adri, copias y actualizaciones

Guía de producto (a quién, qué gratis y qué con Adri, reglas del móvil): [`PRODUCTO.md`](PRODUCTO.md). Manual de uso:
[`MANUAL.md`](MANUAL.md).

- **Quién acepta**: aceptar (cerrar la venta) es del comercial o del administrador, también por la API y por MCP. El
  cliente (invitado o con cuenta) pide el presupuesto: «Quiero este presupuesto» deja nombre, teléfono, correo
  opcional, un mensaje y el consentimiento; la configuración pasa a `requested` («Presupuesto pedido»: precio y
  despiece congelados, ya no se edita) y se crea un **lead**. Aceptar una pedida no recalcula: se mantiene el precio
  que vio el cliente. Contra abusos: campo trampa (honeypot), 5 peticiones por conexión y hora.
- **«Guárdala»**: si el cliente deja su correo, recibe el enlace de solo lectura (cuerpo sin nada escrito por él;
  3 al día por dirección). **«Envíamela por WhatsApp»**: enlace `/ver/{código}.{caducidad}.{firma}` (HMAC con la clave
  de la instalación, 30 días, siempre con la URL pública, sin datos personales ni en la URL ni en la página;
  `noindex` y `Referrer-Policy: no-referrer`; una anulada lo mata).
- **Estado de trabajo** (aparte del de venta) en las aceptadas y en los leads: `nuevo` → `en_curso` → `hecho`, con
  una nota de una línea (≤ 200), quién (persona o token) y cuándo. «Pendientes» = aceptadas con el trabajo nuevo o en
  curso (lo pedido por el cliente se trabaja en su lead). Marcar no toca el precio ni el despiece.
- **Deshacer** en la pantalla: antes de cada cambio se guarda una foto de las líneas (pila de 10 en la sesión);
  deshacer las repone con su id, orden y ancho, y el precio lo vuelve a calcular el servidor. No deshace si alguien la
  cambió después desde otro sitio (por ejemplo, por MCP).
- **Móvil del cliente**: portada con «Empezar» (sin cuenta); paso 1 en cuadrícula de 2 columnas con la ilustración y
  sin niveles de una sola opción; paso 2 con el esquema de cada pared (cajas proporcionales al ancho, pegajoso) y
  tarjetas compactas que se despliegan (tramos como botones, «Cambiar por otro» con iconos propios, quitar con
  deshacer); paso 3 con el total grande y las tres salidas. Sin jerga (código, referencias, «Borrador»). Menos de
  100 KB por pantalla (prueba). Iconos de módulo SVG propios según la familia y el nombre del tipo.
- **Adri en el panel**: tarjeta «Conecta Adri» con la cuenta real de aceptadas y pedidas y ejemplos de planos, y
  acciones «Con Adri» apagadas en la ficha; si un token MCP se usó en los últimos 30 días, «Adri conectada · último uso
  hace N días» y nada más. Al cliente final, solo el pie de la licencia. La app no llama a casa.
- **MCP**: `lead_list`, `lead_get` (read); `config_share`, `config_mark`, `lead_mark` (write, no irreversibles);
  `config_list` con `work_status` y `pendientes`.
- **Copias de seguridad** (`app/backup.php`, patrón de Universal Web en .zip): base por `VACUUM INTO`, la
  configuración como DATOS (`config.json`), las imágenes y `manifest.json` con el sha256 de cada archivo; en
  `data/backups`, descarga solo por POST de admin, cifrado opcional al descargar (libsodium). Restaurar valida rutas,
  tamaños, huellas, la base y el esquema; hace una copia previa, entra en mantenimiento, sustituye y migra; exige
  escribir RESTAURAR. Nunca restaura código: las claves se reponen solo si se marca, y la app escribe su propio
  `config.php` desde el JSON validado. Automáticas diarias o semanales con retención.
- **Actualizaciones** (`app/update.php`, `update_finish.php`, `update_rollback.php`, `maintenance.php`; patrón de
  Universal Web): releases públicas de `AppNetDeveloper/universal-configurator` solo cuando el admin lo pide (o una vez
  al día si lo activa); sobre firmado ed25519 con el sha256 y el tamaño del zip; copia previa, mantenimiento, reemplazo
  archivo a archivo (nunca `data/`), migración y prueba de salud en un proceso nuevo; si algo falla, vuelve sola a la
  versión anterior (código y base). `bin/release` empaqueta y firma (la clave privada, fuera del repositorio).
