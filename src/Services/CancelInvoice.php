<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Services;

use DOMDocument;
use DOMException;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceCancellation;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Constants\AEAT;
use Taiwanleaftea\TltVerifactu\Exceptions\GeneratorException;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;
use Taiwanleaftea\TltVerifactu\Traits\BuildInformationSystem;
use Taiwanleaftea\TltVerifactu\Traits\EnvelopeXml;
use Taiwanleaftea\TltVerifactu\Traits\SanitizeXml;
use Taiwanleaftea\TltVerifactu\Traits\SignXml;

class CancelInvoice
{
    use BuildInformationSystem, EnvelopeXml, SanitizeXml, SignXml;

    /**
     * @var DOMDocument
     */
    protected DOMDocument $document;

    /**
     * @var VerifactuSettings
     */
    protected VerifactuSettings $settings;

    protected bool $generated = false;
    protected bool $signed = false;

    public function __construct(VerifactuSettings $settings)
    {
        $this->document = new DOMDocument('1.0', 'utf-8');
        $this->settings = $settings;
    }

    /**
     * @param InvoiceCancellation $cancellation
     * @return DOMDocument
     * @throws DOMException
     * @throws GeneratorException
     * @throws InvoiceValidationException
     */
    public function getXml(InvoiceCancellation $cancellation): DOMDocument
    {
        $namespace = AEAT::SF_NAMESPACE;
        $dom = $this->document;
        $dom->formatOutput = true;

        // RegistroAnulacion, required
        $registroAnulacion = $dom->createElementNS($namespace, 'sf:RegistroAnulacion');
        $dom->appendChild($registroAnulacion);

        // IDVersion, required
        $registroAnulacion->appendChild($dom->createElementNS($namespace, 'sf:IDVersion', AEAT::VERSION));

        // IDFactura, required
        $idFactura = $dom->createElementNS($namespace, 'sf:IDFactura');
        $registroAnulacion->appendChild($idFactura);
        $idFactura->appendChild($dom->createElementNS($namespace, 'sf:IDEmisorFacturaAnulada', $cancellation->issuer->id));
        $idFactura->appendChild($dom->createElementNS($namespace, 'sf:NumSerieFacturaAnulada', $cancellation->invoiceNumber));
        $idFactura->appendChild($dom->createElementNS($namespace, 'sf:FechaExpedicionFacturaAnulada', $cancellation->getDate()));

        if ($cancellation->hasGenerator()) {
            $generatorData = $cancellation->getGenerator();
            // GeneradoPor, optional
            $registroAnulacion->appendChild($dom->createElementNS($namespace, 'sf:GeneradoPor', $generatorData->generatorType->value));

            // Generador, required in case generator is set
            $generador = $dom->createElementNS($namespace, 'sf:Generador');
            $registroAnulacion->appendChild($generador);

            $generador->appendChild($dom->createElementNS($namespace, 'sf:NombreRazon', $generatorData->name));

            if ($generatorData->isDomestic()) {
                $generador->appendChild($dom->createElementNS($namespace, 'sf:NIF', (string) $generatorData->id));
            } else {
                $idOtro = $dom->createElementNS($namespace, 'sf:IDOtro');
                $generador->appendChild($idOtro);

                $idOtro->appendChild($dom->createElementNS($namespace, 'sf:CodigoPais', $generatorData->countryCode));
                $idOtro->appendChild($dom->createElementNS($namespace, 'sf:IDType', $generatorData->idType->value));
                $idOtro->appendChild($dom->createElementNS($namespace, 'sf:ID', $generatorData->id));
            }
        }

        // Encadenamiento (required)
        $encadenamiento = $dom->createElementNS($namespace, 'sf:Encadenamiento');
        $registroAnulacion->appendChild($encadenamiento);

        if ($cancellation->isFirstInvoice()) {
            // PrimerRegistro
            $encadenamiento->appendChild($dom->createElementNS($namespace, 'sf:PrimerRegistro', AEAT::YES));
        } else {
            // RegistroAnterior
            $previousInvoice = $cancellation->getPreviousInvoice();
            $registroAnterior = $dom->createElementNS($namespace, 'sf:RegistroAnterior');
            $encadenamiento->appendChild($registroAnterior);

            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:IDEmisorFactura', $cancellation->issuer->id));
            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:NumSerieFactura', $previousInvoice['number']));
            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:FechaExpedicionFactura', $previousInvoice['date']));
            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:Huella', $previousInvoice['hash']));
        }

        $sistemaInformatico = self::buildInformationSystem($dom, $namespace, $this->settings->getInformationSystem());

        if ($sistemaInformatico !== false) {
            $registroAnulacion->appendChild($sistemaInformatico);
        }

        // FechaHoraHusoGenRegistro
        $registroAnulacion->appendChild($dom->createElementNS($namespace, 'sf:FechaHoraHusoGenRegistro', $cancellation->getTimestamp()));

        // TipoHuella
        $registroAnulacion->appendChild($dom->createElementNS($namespace, 'sf:TipoHuella', AEAT::SHA_256));

        // Huella
        $registroAnulacion->appendChild($dom->createElementNS($namespace, 'sf:Huella', $cancellation->hash($cancellation->getTimestamp())));

        $this->document = $dom;
        $this->generated = true;

        // TODO return errors
        return $dom;
    }
}
