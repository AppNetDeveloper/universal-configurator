# Universal Configurator — producto

> Qué vendemos, a quién y cómo. Guía para las próximas versiones. El diseño técnico vive en `DISENO.md`; el manual de
> la IA en `MCP.md`.

## La idea en una frase

**La app es gratis y hace que cualquier fabricante enseñe precios bien; Adri es la que trabaja: dibuja, presupuesta,
persigue y fabrica.** La app capta clientes; Adri es la cuota mensual.

## Tres personas, tres experiencias

| Quién | Qué quiere | Qué le damos |
|---|---|---|
| **El cliente final** (el cliente de nuestro cliente), en su móvil, en la tienda o en casa | Ver su cocina/armario y su precio sin líos, en 2 minutos | Un asistente de 3 pasos, con imágenes grandes, un dedo, sin registrarse, precio siempre a la vista, y «guárdala / envíamela / que me llamen» |
| **El comercial** del fabricante, con la tablet delante del cliente | Cerrar la venta en la visita | Configurar rápido, cambiar medidas con − / +, enseñar el precio y aceptarla al momento; luego Adri hace el resto |
| **El fabricante** (dueño/admin) | Catálogo, precios y producción bajo control | Panel claro: catálogo en árbol, tramos, despiece por fórmulas, configuraciones; y Adri conectado para no hacer nada a mano |

## Gratis (la app) frente a con Adri (lo que se vende)

| La app sola (gratis, código fuente disponible) | Con Adri conectada por MCP (asientos mensuales) |
|---|---|
| Catálogo en árbol, tramos, despiece por fórmulas | **Planos CAD automáticos**: planta, alzados, cada módulo, cada pieza (DXF para AutoCAD + PDF) |
| Configurador en tablet/móvil con precio al momento | **Presupuesto profesional en PDF** con la marca, el plano y las condiciones, desde BionicEye |
| Aceptar y guardar; descargar JSON/CSV | **Lista de corte** y m² de tablero por espesor para el taller; después, optimización del corte (fase 2) |
| Panel con configuraciones y personas | **Configurar hablando**: «quita el lavavajillas y pon la placa de 90» por chat, voz o WhatsApp; Adri cambia, recalcula y rehace planos |
| MCP para cualquier IA | **Seguimiento comercial**: alta del lead en el CRM, envío del presupuesto, recordatorio si no contesta, cita de medición en la agenda |
| | **Del aceptado a producción**: pedido en el ERP, orden de fabricación con despiece, pedido de material al proveedor (fase 2–3) |
| | **Lo que se vende y lo que no**: qué modelos, medidas y acabados cierran más; avisos de precios por debajo de margen |

## La publicidad dentro de la app (con gusto, sin molestar)

- **Al cliente final, casi nada**: el pie «Hecho con Universal Configurator · Powered by bionicadri.com» (lo exige la
  licencia) y nada más. Su experiencia es sagrada: si le molestamos, el fabricante quita la app.
- **Al fabricante, sí, y útil**: en su panel, la tarjeta **«Conecta Adri»** con lo que ganaría HOY con SUS datos
  («tienes 12 configuraciones aceptadas: Adri te sacaría sus 12 juegos de planos y la lista de corte»), un ejemplo real
  de planos (los de `docs/ejemplos-adri/`), y el botón para conectarla (que lleva a crear el token y a BionicEye).
- En cada configuración del panel, acciones **«Con Adri»** visibles pero apagadas (Planos CAD, Presupuesto PDF, Lista
  de corte) con una línea de qué hacen. Si Adri ya está conectada (se ha usado un token MCP en los últimos 30 días),
  la tarjeta cambia a «Adri conectada · último uso hace N días» y desaparece la publicidad.
- Nunca: banners, ventanas emergentes, datos que salgan de la instalación del cliente. La app no llama a casa.

## El cliente final en el móvil: reglas de diseño

1. **Tres pasos y uno a la vista**: Elige (imágenes grandes) → Ajusta (módulos como tarjetas) → Listo (precio y qué
   hacer). La barra del precio siempre abajo, con el pulgar.
2. **Un dedo, sin teclado salvo que quiera**: − / + de 1 cm, tramos como botones («41–60 cm · 190 €»), sustituir
   eligiendo de una lista visual. El teclado solo si toca el número.
3. **Imágenes antes que palabras**: cada modelo y acabado con su ilustración; cada módulo con su icono.
4. **Sin registrarse para empezar**; al final, «Guárdala» (correo) o «Envíamela por WhatsApp» (enlace) o «Que me
   llamen» (deja nombre y teléfono → lead para el fabricante y para Adri).
5. **Nada que pensar**: la nota de tramo en llano («50,5 cm se cobra como 51–60 cm»), errores que dicen qué hacer,
   y deshacer el último cambio.
6. **Rápida**: sin librerías externas, cada pantalla < 100 KB, funciona con mala cobertura.

## Hoja de ruta

- **v0.2 (hecha)**: datos de demo llenos (cocinas, armarios, baños), ilustraciones propias, subida de imágenes, capturas.
- **v0.3 (hecha)**: rediseño móvil según las reglas de arriba; compartir por enlace/WhatsApp; «Quiero este presupuesto»
  (lead); deshacer; iconos de módulo; tarjeta «Conecta Adri» y acciones «Con Adri»; copias y actualización automática;
  MCP `lead_list`, `lead_get`, `config_share`, `config_mark`, `lead_mark` y `config_list(pendientes)`.
- **v0.4 (Adri vende)**: en ADRI, del aceptado a
  presupuesto de BionicEye con plano adjunto; cita de medición; recordatorio.
- **v0.5 (producción)**: orden de fabricación, optimización de corte por tableros, pedido de material.
