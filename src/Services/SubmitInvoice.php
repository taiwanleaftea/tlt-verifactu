<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Helpers;

use DOMDocument;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceSubmission;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Constants\Verifactu;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;
use Taiwanleaftea\TltVerifactu\Exceptions\RecipientException;
use Taiwanleaftea\TltVerifactu\Traits\BuildInformationSystem;
use Taiwanleaftea\TltVerifactu\Traits\EnvelopeXml;
use Taiwanleaftea\TltVerifactu\Traits\SignXml;

class SubmitInvoice
{
    use BuildInformationSystem, SignXml, EnvelopeXml;

    /**
     * Generate Invoice submission XML
     *
     * @param InvoiceSubmission $invoice
     * @param VerifactuSettings $settings
     * @return DOMDocument
     * @throws InvoiceValidationException
     * @throws RecipientException
     * @throws \DOMException
     */
    public static function getXml(InvoiceSubmission $invoice, VerifactuSettings $settings): DOMDocument
    {
        $namespace = Verifactu::SF_NAMESPACE;
        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->formatOutput = true;

        // RegistroAlta, required
        $registroAlta = $dom->createElementNS($namespace, 'sf:RegistroAlta');
        $dom->appendChild($registroAlta);

        // IDVersion, required
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:IDVersion', Verifactu::VERSION));

        // IDFactura, required
        $idFactura = $dom->createElementNS($namespace, 'sf:IDFactura');
        $registroAlta->appendChild($idFactura);
        $idFactura->appendChild($dom->createElementNS($namespace, 'sf:IDEmisorFactura', $invoice->issuer->id));
        $idFactura->appendChild($dom->createElementNS($namespace, 'sf:NumSerieFactura', $invoice->invoiceNumber));
        $idFactura->appendChild($dom->createElementNS($namespace, 'sf:FechaExpedicionFactura', $invoice->getDate()));

        // RefExterna, optional
        if (!empty($invoice->externalReference)) {
            $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:RefExterna', $invoice->externalReference));
        }

        // NombreRazonEmisor, required
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:NombreRazonEmisor', $invoice->issuer->name));

        // TipoFactura, required
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:TipoFactura', $invoice->invoiceType->value));

        // DescripcionOperacion, required
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:DescripcionOperacion', $invoice->description));

        // FacturaSimplificadaArt7273
        if ($invoice->invoiceType == InvoiceType::SIMPLIFIED) {
            $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:FacturaSimplificadaArt7273', Verifactu::YES));
        } else {
            $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:FacturaSimplificadaArt7273', Verifactu::NO));

            // Destinatario
            $destinatarios = $dom->createElementNS($namespace, 'sf:Destinatarios');
            $registroAlta->appendChild($destinatarios);

            // IDDestinatario
            $recipient = $invoice->getRecipient();
            $idDestinatario = $dom->createElementNS($namespace, 'sf:IDDestinatario');
            $destinatarios->appendChild($idDestinatario);
            $idDestinatario->appendChild($dom->createElementNS($namespace, 'sf:NombreRazon', $recipient->name));

            if ($recipient->isDomestic()) {
                $idDestinatario->appendChild($dom->createElementNS($namespace, 'sf:NIF', $recipient->id));
            } else {
                $idOtro = $dom->createElementNS($namespace, 'sf:IDOtro');
                $idDestinatario->appendChild($idOtro);

                $idOtro->appendChild($dom->createElementNS($namespace, 'sf:CodigoPais', $recipient->countryCode));
                $idOtro->appendChild($dom->createElementNS($namespace, 'sf:IDType', $recipient->idType->value));
                $idOtro->appendChild($dom->createElementNS($namespace, 'sf:ID', $recipient->id));
            }
        }

        // Desglose, required
        $desglose  = $dom->createElementNS($namespace, 'sf:Desglose');
        $registroAlta->appendChild($desglose);

        // DetalleDesglose, required
        $detalleDesglose = $dom->createElementNS($namespace, 'sf:DetalleDesglose');
        $desglose->appendChild($detalleDesglose);

        // Impuesto, optional
        $detalleDesglose->appendChild($dom->createElementNS($namespace, 'sf:Impuesto', $invoice->getTaxType()));

        // ClaveRegimen, optional
        $detalleDesglose->appendChild($dom->createElementNS($namespace, 'sf:ClaveRegimen', $invoice->getTaxRegimeIVA()));

        if (!isset($invoice->exemptOperation)) {
            // CalificacionOperacion, required
            $detalleDesglose->appendChild($dom->createElementNS($namespace, 'sf:CalificacionOperacion', $invoice->getOperationQualification()));
        } else {
            // OperacionExenta, required
            $detalleDesglose->appendChild($dom->createElementNS($namespace, 'sf:OperacionExenta', $invoice->exemptOperation->value));
        }

        // TipoImpositivo
        $detalleDesglose->appendChild($dom->createElementNS($namespace, 'sf:TipoImpositivo', $invoice->getTaxRate()));

        // BaseImponibleOimporteNoSujeto
        $detalleDesglose->appendChild($dom->createElementNS($namespace, 'sf:BaseImponibleOimporteNoSujeto', $invoice->getTaxableBase()));

        // CuotaRepercutida
        $detalleDesglose->appendChild($dom->createElementNS($namespace, 'sf:CuotaRepercutida', $invoice->getTaxAmount()));

        // TODO RecargoEquivalencia

        // CuotaTotal, required
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:CuotaTotal', $invoice->getTaxAmount()));

        // ImporteTotal (required)
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:ImporteTotal', $invoice->getTotalAmount()));

        // Encadenamiento (required)
        $encadenamiento = $dom->createElementNS($namespace, 'sf:Encadenamiento');
        $registroAlta->appendChild($encadenamiento);

        if ($invoice->isFirstInvoice()) {
            // PrimerRegistro
            $encadenamiento->appendChild($dom->createElementNS($namespace, 'sf:PrimerRegistro', Verifactu::YES));
        } else {
            // RegistroAnterior
            $previousInvoice = $invoice->getPreviousInvoice();
            $registroAnterior = $dom->createElementNS($namespace, 'sf:RegistroAnterior');
            $encadenamiento->appendChild($registroAnterior);

            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:IDEmisorFactura', $invoice->issuerNif));
            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:NumSerieFactura', $previousInvoice['number']));
            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:FechaExpedicionFactura', $previousInvoice['date']));
            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:Huella', $previousInvoice['hash']));
        }

        $sistemaInformatico = self::buildInformationSystem($dom, $namespace, $settings->getInformationSystem());

        if ($sistemaInformatico !== false) {
            $registroAlta->appendChild($sistemaInformatico);
        }

        // FechaHoraHusoGenRegistro
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:FechaHoraHusoGenRegistro', (string) $invoice->getTimestamp()));

        // TipoHuella
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:TipoHuella', Verifactu::SHA_256));

        // Huella
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:Huella', $invoice->hash()));

        return $dom;
    }
}
