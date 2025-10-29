<?php

namespace Taiwanleaftea\TltVerifactu\Enums;

/*
 * Estado del registro. Correcto o Incorrecto
 */
enum EstadoRegistro: string
{
    case ACCEPTED = 'Correcto';

    // Aceptado con Errores. Ver detalle del error
    case ACCEPTED_ERRORES = 'AceptadoConErrores';

    case NOT_ACCEPTED = 'Incorrecto';
}
