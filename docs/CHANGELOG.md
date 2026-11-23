# Cambios

## 0.3.1

Arreglos de una revisión de seguridad independiente (cada uno con su prueba, primero en rojo).

- **root no ejecuta lo que puede escribir el servidor web.** `bin/uc` se niega a correr como root (salvo `install`,
  que antes de tocar `data/` pasa al dueño de `data/`). Terminar una actualización: sin `uid`, con un `pending.json`
  que sea un enlace o con un `rollback.php` que no sea del mismo usuario, no se hace. Los textos dicen
  `sudo -u www-data php bin/uc …`; con el código del instalador, se actualiza con `sudo ./install.sh` de la versión
  nueva. Una actualización lanzada como root con la 0.3.0 la termina el servidor web en la siguiente visita.
- **Actualizaciones:** el paquete se comprueba (sha256) y se extrae de la misma copia privada.
- **Límites atómicos:** `throttle()` cuenta y comprueba en una sola sentencia (antes, con peticiones simultáneas,
  entraban 15 peticiones de presupuesto con un límite de 5). Requisito nuevo: SQLite 3.35.
- **Compartir y cambiar con límite:** `config_share` 60 por hora (el mismo día da el mismo enlace y una sola fila en
  la actividad); cambios y deshacer, 600 llamadas cada 10 minutos por persona o conexión.
- **Restaurar:** las imágenes se vuelven a codificar con GD (sin GD, una copia con imágenes no se restaura); el tope
  anti «zip bomb» cuenta lo que de verdad se descomprime y cada archivo tiene que ocupar lo que declara.
- **`page` gigantesco** ya no da 500 (API, panel, MCP).
- **Copias con nombre aleatorio** y `data/` más cerrada si Apache no lee `.htaccess` (el manual explica
  `AllowOverride None`).

## 0.3.0

**El cliente final en el móvil**
- Portada con «Empezar» (sin cuenta); «Entrar» del equipo, discreto.
- Paso 1 en cuadrícula de 2 columnas con ilustraciones grandes; los niveles con una sola opción no se preguntan.
- Paso 2 con el esquema de cada pared (cajas proporcionales al ancho; tocar una lleva a su tarjeta), tarjetas compactas
  que se despliegan con los tramos como botones, «Cambiar por otro» con iconos propios, quitar y **deshacer**.
- Paso 3 con el total grande y tres salidas: «Quiero este presupuesto» (lead), «Envíamela por WhatsApp» (enlace de
  solo lectura, 30 días) y «Seguir cambiando». Aceptar queda para el comercial y el administrador.
- Sin jerga para el cliente; cada pantalla pesa menos de 100 KB.

**Panel**
- Leads con estado (nuevo, contactado, cerrado) y estado de trabajo con nota.
- Estado de trabajo en las aceptadas, filtro «pendientes», «Aceptar» para las pedidas.
- Tarjeta «Conecta Adri» con tus cifras y ejemplos de planos; acciones «Con Adri» en la ficha.
- Copias de seguridad (manuales y automáticas, descarga cifrada, restaurar comprobando todo).
- Actualizaciones desde el panel (firmadas, con copia previa y vuelta atrás sola si algo falla).

**MCP**
- `lead_list`, `lead_get`, `config_share`, `config_mark`, `lead_mark`; `config_list` con `pendientes` y `work_status`.

**Datos**
- Esquema 7: estado «Presupuesto pedido», estado de trabajo y tabla de leads (la migración conserva todo).

## 0.2.0

- Datos de ejemplo de tres familias (cocinas, armarios, baños), `bin/uc demo`, ilustraciones propias y subida de
  imágenes del catálogo; capturas de todas las pantallas.

## 0.1.0

- Primera versión: catálogo en árbol, tramos, despiece por fórmulas, configurador de 3 pasos, panel y MCP.
