# Capturas (v0.3)

Todas las pantallas con la **demostración** cargada (`bin/uc demo`: tres familias, 3 comerciales, 4 clientes, 14
configuraciones y leads ficticios). Tres tamaños: escritorio 1440×900, tablet 1024×768 y móvil 390×844. Los nombres,
correos y teléfonos son inventados (`@example.com`). Se hacen con `tests/capturas.cjs` (ver «Cómo se rehacen»).

## El cliente final, en su móvil

**Portada.** Lo primero es «Empezar» (sin cuenta) con las ilustraciones de las familias; «Entrar» del equipo, discreto
debajo. El equipo entra por `/entrar`.

| Portada (móvil) | Portada (escritorio) | Entrar del equipo |
|---|---|---|
| ![Portada](capturas/portada-movil.png) | ![Portada](capturas/portada-escritorio.png) | ![Entrar](capturas/entrar-equipo-escritorio.png) |

**Paso 1 — Elige.** Cuadrícula de 2 columnas con la ilustración grande (SVG propio, el acabado como color). Un nivel con
una sola opción no se pregunta.

| Familias | Modelos de cocina | Acabados de un armario |
|---|---|---|
| ![Familias](capturas/paso1-familias-movil.png) | ![Cocina](capturas/paso1-cocina-movil.png) | ![Acabados](capturas/paso1-acabados-movil.png) |

**Paso 2 — Ajusta.** Arriba, el esquema de cada pared (cajas proporcionales al ancho; tocar una lleva a su tarjeta).
Cada módulo es una tarjeta compacta: icono, nombre, precio y − ancho +. Al tocarla se despliega: los tramos como
botones (el de 50,5 cm, en el hueco entre 41–50 y 51–60, se cobra como 51–60 y lo dice), «Cambiar por otro» con una
lista de iconos y «Quitar». Quitar se deshace con un toque.

| Arriba | Hueco de tramo | Cambiar por otro | Quitado, con «Deshacer» |
|---|---|---|---|
| ![Paso 2](capturas/paso2-cliente-movil.png) | ![Hueco](capturas/paso2-hueco-de-tramo-movil.png) | ![Cambiar](capturas/paso2-cambiar-por-movil.png) | ![Deshacer](capturas/paso2-quitado-deshacer-movil.png) |

**Paso 3 — Listo.** El total grande (sin y con IVA), «Quiero este presupuesto» (nombre, teléfono, correo opcional,
consentimiento → lead), «Envíamela por WhatsApp» y «Seguir cambiando». El total, siempre junto al botón. Al pedirlo:
«¡Recibido!». El enlace compartido es de solo lectura y no lleva datos del cliente.

| Paso 3 | ¡Recibido! | Enlace compartido |
|---|---|---|
| ![Paso 3](capturas/paso3-cliente-movil.png) | ![Recibido](capturas/paso3-recibido-movil.png) | ![Enlace](capturas/enlace-compartido-movil.png) |

En escritorio: [paso 1](capturas/paso1-familias-escritorio.png) · [paso 2](capturas/paso2-cliente-escritorio.png) ·
[hueco de tramo](capturas/paso2-hueco-de-tramo-escritorio.png) · [paso 3](capturas/paso3-cliente-escritorio.png) ·
[recibido](capturas/paso3-recibido-escritorio.png) · [enlace](capturas/enlace-compartido-escritorio.png).
En tablet: [portada](capturas/portada-tablet.png) · [paso 1](capturas/paso1-familias-tablet.png) ·
[paso 2](capturas/paso2-cliente-tablet.png) · [cambiar por otro](capturas/paso2-cambiar-por-tablet.png) ·
[paso 3](capturas/paso3-cliente-tablet.png) · [recibido](capturas/paso3-recibido-tablet.png).

## El comercial, con la tablet

En la tablet el esquema queda a la izquierda (pegajoso) y las tarjetas a la derecha. El comercial ve el código y las
referencias, y **acepta**: el precio queda guardado.

| Paso 2 | Resumen y aceptar | Aceptada | Mis configuraciones |
|---|---|---|---|
| ![Paso 2](capturas/paso2-comercial-tablet.png) | ![Resumen](capturas/paso3-comercial-escritorio.png) | ![Aceptada](capturas/paso3-aceptada-escritorio.png) | ![Mis](capturas/mis-configuraciones-escritorio.png) |

## El panel del administrador

**Inicio** con los leads nuevos y las pendientes de trabajar, y la tarjeta **«Conecta Adri»** con las cifras reales
de la instalación y planos de ejemplo. Con un token MCP usado en los últimos 30 días, la tarjeta se queda en una línea:
«Adri conectada · último uso hace N días».

| Sin Adri | Con Adri conectada | En el móvil |
|---|---|---|
| ![Inicio](capturas/panel-inicio-escritorio.png) | ![Adri conectada](capturas/panel-inicio-adri-conectada-escritorio.png) | ![Inicio móvil](capturas/panel-inicio-movil.png) |

**Leads**: lo que deja el cliente, con su configuración, el estado (nuevo, contactado, cerrado) y el trabajo con nota.

| Escritorio | Tablet | Móvil |
|---|---|---|
| ![Leads](capturas/panel-leads-escritorio.png) | ![Leads tablet](capturas/panel-leads-tablet.png) | ![Leads móvil](capturas/panel-leads-movil.png) |

**Configuraciones** (filtros por estado, trabajo, «solo pendientes», fechas y persona) y la **ficha**: despiece de
cada módulo, lista de corte, estado de trabajo y acciones «Con Adri» (apagadas mientras Adri no está conectada). Una
pedida enseña el lead y el botón «Aceptar (cerrar la venta)».

| Lista | Pendientes | Ficha | Pedida | Ficha con Adri |
|---|---|---|---|---|
| ![Lista](capturas/panel-configuraciones-escritorio.png) | ![Pendientes](capturas/panel-configuraciones-pendientes-escritorio.png) | ![Ficha](capturas/panel-ficha-escritorio.png) | ![Pedida](capturas/panel-ficha-pedida-escritorio.png) | ![Con Adri](capturas/panel-ficha-adri-conectada-escritorio.png) |

**Catálogo, tipos y tramos, despiece y composición**:

| Catálogo | Un nodo | Tipos | Cajonera: tramos y despiece | Corredera | Composición |
|---|---|---|---|---|---|
| ![Catálogo](capturas/panel-catalogo-escritorio.png) | ![Nodo](capturas/panel-nodo-escritorio.png) | ![Tipos](capturas/panel-tipos-escritorio.png) | ![Cajonera](capturas/panel-tipo-tramos-despiece-escritorio.png) | ![Corredera](capturas/panel-tipo-corredera-escritorio.png) | ![Composición](capturas/panel-composicion-escritorio.png) |

**Copias de seguridad y actualizaciones.** La copia avisa de que lleva las claves; se descarga (con cifrado opcional),
se restaura escribiendo RESTAURAR o se borra. En Actualizaciones, la versión instalada, la nueva con sus cambios y
cuándo se busca (la única conexión de salida). En esta captura la copia es de Git, y el panel lo dice en vez de
actualizar: se actualiza desde la terminal.

| Copias | Copias (móvil) | Actualizaciones |
|---|---|---|
| ![Copias](capturas/panel-copias-escritorio.png) | ![Copias móvil](capturas/panel-copias-movil.png) | ![Actualizaciones](capturas/panel-actualizaciones-escritorio.png) |

**Personas, ajustes (con el correo SMTP), tokens MCP (el de la demo está revocado: un token vivo solo se ve una vez) y
actividad**:

| Personas | Ajustes | Tokens MCP | Actividad |
|---|---|---|---|
| ![Personas](capturas/panel-personas-escritorio.png) | ![Ajustes](capturas/panel-ajustes-escritorio.png) | ![Tokens](capturas/panel-tokens-escritorio.png) | ![Actividad](capturas/panel-actividad-escritorio.png) |

## Lo que se corrigió al mirarlas

**v0.3**
- El primer módulo del paso 2 salía al 63 % de la pantalla del móvil y 10 módulos ocupaban 3,3 pantallas: cabecera
  y esquema más compactos → al 49 % (con el esquema de tres paredes encima) y 2,9 pantallas.
- El botón «Deshacer» aparecía sin haber nada que deshacer (el estilo del botón pisaba el atributo `hidden`).
- El paso 3 del móvil se salía por la derecha (el botón «Quiero este presupuesto · 1.450 €» no se partía).
- Las cajas del esquema con la altura de cada módulo confundían: ahora todas igual, proporcionales al ancho.
- Los nombres largos se cortaban («Lavavajillas inte…»): ahora en dos líneas.
- Al desplegar una tarjeta, lo desplegado quedaba bajo la barra del total: ahora se desplaza para verse.
- Los iconos de módulo daban errores en la consola (un fallo al montar el trazo del SVG).
- La pantalla del paso 2 rozaba los 100 KB: fuera el despiece de los datos de la pantalla (98 → 87 KB).
- «Entrar» del equipo decía «Diseña tu producto…»: ahora habla al equipo.
- La tabla de copias no cabía en el móvil: ahora una tarjeta por copia.
- El menú del panel se cortaba en escritorio (Actualizaciones, Actividad): ahora usa todo el ancho.

**v0.2**
- Tiradores invisibles en acabados oscuros; tarjetas sueltas en la cuadrícula; despiece del tipo que se salía en
  escritorio; «60–60 cm»; la composición ofrecía módulos de otras familias; árbol y tablas que se salían en el móvil.

## Antes y después (recorrido del móvil)

El mismo recorrido (iPhone 13; portada → paso 1 → Cocina → Dúo → Roble → paso 2 → «+» → resumen) hecho sobre la v0.2
y sobre la v0.3 (con `tests/recorrido-movil.cjs`, que además mide el paso 2) se comparó lado a lado. Qué mejoró:
la portada empieza por «Empezar»; el paso 1 se ve en dos columnas con la ilustración; el paso 2 ya no gasta media
pantalla en pasos, ruta y párrafo, enseña el esquema y tarjetas compactas sin referencias ni código; el paso 3 ya no
pide «Aceptar» al cliente. Qué sigue flojo: con tres paredes, el esquema pegajoso ocupa unos 110 px del móvil; las
ilustraciones de planta (cocina en L y en paralelo) se leen peor que las de alzado.

## Cómo se rehacen

```bash
docker run -d --name uc-dev -p 127.0.0.1:8190:8190 -e UC_DATA=/tmp/uc-data -v "$PWD":/app -w /app wordpress:cli-php8.2 \
  php -S 0.0.0.0:8190 -t public public/index.php
docker exec -e UC_DATA=/tmp/uc-data -e UC_PASSWORD=admin-12345 uc-dev sh -c 'php bin/uc install --empresa="Muebles Ébano" \
  --email=admin@example.org --name="Carmen Vidal" --url=http://127.0.0.1:8190 && php bin/uc demo && php -r "require \"app/bootstrap.php\";
  settings_save([\"permitir_registro\"=>\"1\",\"permitir_invitado\"=>\"1\",\"mail_transport\"=>\"log\",\"smtp_host\"=>\"smtp.muebles-ebano.example\",
  \"backup_auto\"=>\"daily\"]); backup_create(true); backup_create();"'
PW_CORE=/ruta/a/playwright-core CHROME=/ruta/a/chrome node tests/capturas.cjs /tmp/capturas
# «Adri conectada»: crea un token, márcalo como usado y repite solo esas capturas
docker exec -e UC_DATA=/tmp/uc-data uc-dev sh -c 'php bin/uc token --name=ADRI --email=laura.martin@example.com && php -r "require \"app/bootstrap.php\";
  q(\"UPDATE mcp_tokens SET last_used_at = datetime(\x27now\x27, \x27-2 days\x27, \x27localtime\x27) WHERE revoked_at IS NULL\");"'
UC_ADRI_ON=1 PW_CORE=… CHROME=… node tests/capturas.cjs /tmp/capturas
docker rm -f uc-dev
```

El script avisa si alguna página se sale por la derecha. Para la captura de Actualizaciones con «hay versión nueva»
sin conectarse a GitHub, se deja el estado de la búsqueda en la tabla `meta` (`update_state`). Las elegidas se copian a
`docs/capturas/` reducidas a paleta de 256 colores.
