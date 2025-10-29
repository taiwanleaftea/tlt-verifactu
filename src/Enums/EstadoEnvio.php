<?php

namespace Taiwanleaftea\TltVerifactu\Enums;

/*
 * Estado del envío en conjunto.
 * Si los datos de cabecera y todos los registros son correctos, el estado es correcto.
 * En caso de estructura y cabecera correctos donde todos los registros son incorrectos, el estado es incorrecto
 * En caso de estructura y cabecera correctos con al menos un registro incorrecto, el estado global es parcialmente correcto.
 */
enum EstadoEnvio: string
{
    case ACCEPTED = 'Correcto';

    // Parcialmente correcto. Ver detalle de errores
    case PARTIAL_ACCEPTED = 'ParcialmenteCorrecto';

    case NOT_ACCEPTED = 'Incorrecto';
}
