<?php
declare(strict_types=1);
// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)

/**
 * Textos de la interfaz. Todo texto visible pasa por t('Texto en español', ['var' => …]).
 * Para traducir la aplicación basta con este fichero: añade el idioma a TEXTOS con la traducción de cada
 * frase (la clave es la frase en español) y pon UC_IDIOMA. Las frases que falten se muestran en español.
 * Las variables van entre llaves: t('Hola, {nombre}', ['nombre' => 'Ana']).
 */

const UC_IDIOMA = 'es';

const TEXTOS = [
    'es' => [],
    // 'en' => ['Entrar' => 'Sign in', 'Configurar' => 'Configure', …],
];

function t(string $s, array $vars = []): string
{
    $out = TEXTOS[UC_IDIOMA][$s] ?? $s;
    if ($vars) {
        $rep = [];
        foreach ($vars as $k => $v) $rep['{' . $k . '}'] = (string) $v;
        $out = strtr($out, $rep);
    }
    return $out;
}

/** t() escapado para HTML. */
function te(string $s, array $vars = []): string
{
    return e(t($s, $vars));
}
