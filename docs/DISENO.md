# Universal Configurator — diseño (v0.1)

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

- Medidas en **centímetros enteros** (ancho del módulo). Fondo y alto no se configuran en v0.1 (el fondo va en el tipo
  de módulo, en mm, solo para el plano de ADRI).
- **Precio de un módulo** = el del tramo cuyo `[desde_cm, hasta_cm]` (ambos incluidos) contiene su ancho. Si ningún
  tramo lo contiene → la medida NO es válida y se rechaza diciendo qué tramos hay. Los tramos de un tipo no se solapan
  (validar al guardar). Opcional: tramos específicos de un nodo (modelo/color) que pisan a los generales del tipo.
- **Total** = suma de módulos. Moneda EUR. Precios SIN IVA; el IVA (porcentaje en ajustes, por defecto 21) solo se
  MUESTRA aparte. El cálculo vive en UNA función PHP (`precio_configuracion()`), la usan la tablet (vía API), el panel
  y el MCP. Nunca se calcula en JavaScript.
- Al **aceptar** se congela una foto del precio (tramo y precio de cada línea) en la configuración: si luego cambia la
  tabla, lo aceptado no cambia. Una configuración aceptada ya no se edita (se duplica).

## Datos (SQLite, `data/configurator.sqlite`, fuera de `public/`)

```
users(id, email UNIQUE, name, password_hash NULL, role CHECK(role IN('admin','comercial','cliente')), active,
      auth_source CHECK(auth_source IN('local','bionicadri')) DEFAULT 'local', external_id NULL, created_at, last_login_at)
password_resets(token_hash PK, user_id, expires_at)
nodes(id, parent_id NULL → nodes, name, slug, sort, description, image NULL, active)          -- árbol genérico
module_types(id, name, ref, description, family, depth_mm DEFAULT 600, active, sort)
price_tiers(id, module_type_id, node_id NULL, from_cm, to_cm, price, CHECK(from_cm <= to_cm))
layout_walls(id, node_id, name, direction CHECK(direction IN('x','y')), origin_x_mm, origin_y_mm, sort)
default_modules(id, layout_wall_id, module_type_id, width_cm, sort)
configurations(id, code UNIQUE, node_id, user_id NULL, customer_name, customer_email, customer_phone,
      status CHECK(status IN('draft','accepted','cancelled')), total, tax_rate, notes, created_at, updated_at, accepted_at)
config_walls(id, configuration_id, name, direction, origin_x_mm, origin_y_mm, sort)
config_items(id, config_wall_id, module_type_id, width_cm, sort, tier_from_cm, tier_to_cm, price)  -- tier/price: la foto
settings(key PK, value)            -- empresa, logo, iva, permitir_registro, permitir_invitado, bionicadri_* (SSO)
mcp_tokens(id, name, token_hash UNIQUE, scopes, created_at, last_used_at, revoked_at)
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
| `config_accept` | write | Acepta un borrador (congela precios). Devuelve el aviso «la aceptación la debe confirmar la persona» en la descripción |

Errores del MCP «smart»: qué falló (con el valor recibido), qué vale, un ejemplo de llamada y «no se ha hecho nada».
`initialize` devuelve `instructions` con el flujo recomendado (como Universal Web).

## Estructura

```
public/index.php  public/assets/{app.css, app.js}
app/{bootstrap.php, core.php, security.php, auth.php, auth_providers.php, catalog.php, pricing.php,
     configurations.php, admin.php, api.php, mcp.php, install.php, mail.php, views/*.php}
data/  (no servido; .htaccess deny)  tests/*.php  deploy/{nginx,apache,caddy}.example  install.sh  LICENSE  README.md
```

`php -S` para desarrollar. Instalador web en el primer arranque (crea `data/config.php`, la base y el admin) y botón
«cargar datos de ejemplo» (una cocina con 3 modelos, 4 colores, ~9 tipos de módulo con tramos y composiciones).

## Calidad

- Pruebas en `tests/*.php` ejecutables con `php tests/<x>.php` (sin PHPUnit, como Universal Web): motor de precios
  (bordes de tramo, sin tramo, solapes, tramo por nodo), acceso (login, throttle, CSRF, roles, reset caducado), MCP
  (token, ámbitos, cada herramienta, errores), API (un comercial no ve configuraciones de otro).
- Todo SQL con parámetros; toda salida HTML con `e()`; ninguna ruta fuera de `public/` servida.
- Desarrollo y pruebas en Docker aislado: `docker run --rm -v /var/www/universal-configurator:/app -w /app
  wordpress:cli-php8.2 php tests/x.php` (el PHP del host no tiene SQLite y NO se toca).
