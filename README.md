# Universal Configurator

Configurador de productos por catálogo para fabricantes y tiendas (cocinas, muebles, armarios…), con el precio al
momento. PHP puro, sin frameworks, sin Composer, sin Node; SQLite; panel propio; servidor MCP propio. Hermano de
Universal Web.

- El **administrador** carga un catálogo en árbol (p. ej. Cocina → Modelo → Color), los **tipos de módulo** con su
  **tabla de precios por tramos de medida** (de 41 a 60 cm = X €; no se cobra por centímetro) y la **composición por
  defecto** de cada modelo (paredes con sus módulos).
- Un **comercial**, un **cliente** o un **invitado** (si se permite) elige en la tablet hasta un nodo final, ajusta el
  ancho de cada módulo, lo quita, lo sustituye o añade otros, ve el total sin IVA al momento y acepta. Al aceptar, el
  precio queda **congelado**: si luego cambia la tarifa, lo aceptado no cambia.
- El administrador ve todas las configuraciones y las descarga en **JSON** y **CSV**.
- **ADRI** (u otra IA) se conecta por **MCP** y lee o cambia configuraciones con sus medidas exactas para hacer fuera lo
  pesado: plano CAD acotado, PDF profesional, seguimiento. La aplicación no dibuja planos.

El diseño completo (datos, reglas, acceso, pantallas, MCP) está en [`docs/DISENO.md`](docs/DISENO.md).

## Requisitos

- PHP 8.2 o superior con `pdo_sqlite`, `mbstring`, `sodium` y `json`.
- Un servidor web que sirva **solo la carpeta `public/`** (Caddy, Nginx o Apache; ejemplos en `deploy/`).
- La carpeta `data/` escribible por el usuario de PHP (ahí van la base de datos y `config.php`; nunca se sirve).

## Instalación

**En un VPS Ubuntu/Debian** (instala PHP-FPM y Caddy con HTTPS automático):

```bash
sudo ./install.sh --domain=configurador.miempresa.com --email=yo@miempresa.com --empresa="Mi empresa" --sample
```

**En cualquier servidor con PHP**: copia la carpeta, apunta el dominio a `public/` (ver `deploy/nginx.example`,
`deploy/apache.example` o `deploy/caddy.example`) y abre la dirección en el navegador: el instalador comprueba los
requisitos y crea `data/config.php`, la base de datos y la cuenta de administración. Marca «Cargar datos de ejemplo»
para empezar con una cocina de 3 modelos (recta, en L y paralela), 4 colores y 9 tipos de módulo; también se cargan
después desde **Panel → Ajustes**.

**Desde la línea de órdenes** (como el usuario de PHP):

```bash
UC_PASSWORD='…' php bin/uc install --empresa="Mi empresa" --email=yo@miempresa.com --url=https://configurador.miempresa.com --sample
php bin/uc token --name=ADRI --email=yo@miempresa.com --scopes=read,write   # token MCP (se muestra una vez)
php bin/uc reset-link --email=persona@miempresa.com                          # enlace para poner contraseña
```

**Para desarrollar**: `php -S 127.0.0.1:8190 -t public public/index.php`.

La **URL pública** es obligatoria al instalar (el instalador web propone la dirección actual para que la confirmes):
es la única base de los enlaces que salen por correo y de la vuelta de BionicEye. Nunca se toma de la cabecera `Host`
de la petición; si falta, no se envía ningún enlace y el panel avisa.

Después de instalar, en **Panel → Ajustes**: dirección pública, IVA (solo se
muestra aparte), registro libre de clientes, configurar sin cuenta (invitado), y el correo (`mail()`, SMTP o «solo
registrar» en `data/mail.log` para pruebas). En **Panel → Personas** se dan de alta los comerciales: les llega el
enlace para poner su contraseña (y el panel lo enseña por si el correo no sale).

## Conectar ADRI por MCP

1. **Panel → Tokens MCP → Nuevo token**: ponle un nombre (p. ej. «ADRI»), elige **como quién actúa** y los permisos.
   - `read`: leer catálogo y configuraciones. `write`: además crear, cambiar y aceptar.
   - El token actúa como la persona elegida: si es un comercial, solo ve y toca **sus** configuraciones; si es un
     administrador, todas. Si esa persona se desactiva, el token deja de valer.
   - El token se muestra **una sola vez**; se guarda solo su huella. Se revoca desde la misma pantalla.
2. En ADRI (o cualquier cliente MCP) añade el servidor:
   - Dirección: `https://configurador.miempresa.com/mcp` (JSON-RPC 2.0 por POST, sin estado).
   - Cabecera: `Authorization: Bearer uc_…`
3. Herramientas:

| Herramienta | Permiso | Qué hace |
|---|---|---|
| `catalog_tree` | read | Árbol de nodos activos (id, nombre, hijos, si es final) |
| `module_types` | read | Tipos con su fondo y sus tramos (generales y por nodo; con `node_id`, los que valen ahí) |
| `config_list` | read | Configuraciones con filtros (estado, desde, hasta, texto), paginadas |
| `config_get` | read | Una configuración entera: nodo, cliente, paredes con geometría (mm), módulos (cm), tramo y precio por línea, total |
| `price_check` | read | Precio de una lista de módulos para un nodo, sin guardar (y qué medida no es válida) |
| `config_create` | write | Borrador desde un nodo final con su composición por defecto (o `copy_of` para duplicar) |
| `config_update` | write | Cambios en un borrador: `set_width`, `remove`, `add`, `replace`, `customer` (todos o ninguno) |
| `config_accept` | write | Acepta un borrador y congela su precio. La aceptación la debe confirmar la persona |

`initialize` devuelve las instrucciones con el flujo recomendado. Los errores dicen qué falló (con el valor recibido),
qué vale, un ejemplo de llamada y «No se ha hecho nada». Todo queda en **Panel → Actividad**.

Prueba rápida:

```bash
curl -s https://configurador.miempresa.com/mcp -H "Authorization: Bearer uc_…" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"catalog_tree","arguments":{}}}'
```

## Reglas de precio (resumen)

- Anchos en **centímetros enteros**. Cada tipo de módulo tiene tramos `[desde, hasta]` (ambos incluidos) sin solapes.
- Una medida que no cae en ningún tramo **no es válida**: se rechaza diciendo qué tramos hay.
- Un nodo (p. ej. un modelo) puede tener tramos propios de un tipo: **sustituyen** a los generales en ese nodo y sus
  hijos (manda el antepasado más cercano que tenga tramos de ese tipo).
- Total = suma de módulos, en EUR **sin IVA**. El IVA se muestra aparte. El cálculo está en una sola función del
  servidor (`precio_configuracion()` en `app/pricing.php`); la tablet nunca calcula precios.
- Importes guardados en céntimos enteros; la API y el MCP los dan en euros con 2 decimales (y `*_cents`).

## Pruebas

Sin PHPUnit. Cada prueba usa su propia carpeta de datos temporal:

```bash
php tests/run.php          # todas (sale con código ≠ 0 si algo falla)
php tests/pricing.php      # una sola
```

`tests/http.php` levanta `php -S` y recorre la aplicación por HTTP (instalador, panel, tablet, API y MCP).

## Traducir

Todos los textos pasan por `t('…')` y se traducen en un único fichero: `app/textos.php`.

## Licencia

**Licencia BionicAdri 2.0** (ver [`LICENSE`](LICENSE)): uso libre y gratuito para tu propio negocio, también para que
tus clientes, comerciales y distribuidores configuren y acepten tus productos; sin reventa ni servicio a terceros; sin
garantía ni soporte. El crédito «Hecho con Universal Configurator · Powered by bionicadri.com» no se puede quitar.
