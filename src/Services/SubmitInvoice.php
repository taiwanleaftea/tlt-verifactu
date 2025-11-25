<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Services;

use DOMDocument;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceSubmission;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Constants\AEAT;
use Taiwanleaftea\TltVerifactu\Enums\CreditNoteType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;
use Taiwanleaftea\TltVerifactu\Exceptions\RecipientException;
use Taiwanleaftea\TltVerifactu\Traits\BuildInformationSystem;
use Taiwanleaftea\TltVerifactu\Traits\EnvelopeXml;
use Taiwanleaftea\TltVerifactu\Traits\SanitizeXml;
use Taiwanleaftea\TltVerifactu\Traits\SignXml;

class SubmitInvoice
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
     * Generate Invoice submission XML
     *
     * @param InvoiceSubmission $invoice
     * @return DOMDocument
     * @throws InvoiceValidationException
     * @throws RecipientException
     * @throws \DOMException
     */
    public function getXml(InvoiceSubmission $invoice): DOMDocument
    {
        $namespace = AEAT::SF_NAMESPACE;
        $dom = $this->document;
        $dom->formatOutput = true;

        // RegistroAlta, required
        $registroAlta = $dom->createElementNS($namespace, 'sf:RegistroAlta');
        $dom->appendChild($registroAlta);

        // IDVersion, required
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:IDVersion', AEAT::VERSION));

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

        // Subsanación
        if ($invoice->getOption('subsanacion') !== null) {
            $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:Subsanacion', AEAT::YES));
        }

        // TipoFactura, required
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:TipoFactura', $invoice->type->value));

        // TipoRectificativa
        if ($invoice->isRectificado()) {
            $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:TipoRectificativa', CreditNoteType::DIFFERENCE->value));

            // FacturasRectificadas
            $facturasRectificadas = $dom->createElementNS($namespace, 'sf:FacturasRectificadas');
            $registroAlta->appendChild($facturasRectificadas);

            // IDFacturaRectificada
            $iDFacturaRectificada = $dom->createElementNS($namespace, 'sf:IDFacturaRectificada');
            $facturasRectificadas->appendChild($iDFacturaRectificada);

            $rectificado = $invoice->getOption('rectificado');
            $iDFacturaRectificada->appendChild($dom->createElementNS($namespace, 'sf:IDEmisorFactura', $invoice->issuer->id));
            $iDFacturaRectificada->appendChild($dom->createElementNS($namespace, 'sf:NumSerieFactura', $rectificado['invoice_number']));
            $iDFacturaRectificada->appendChild($dom->createElementNS($namespace, 'sf:FechaExpedicionFactura', $rectificado['invoice_date']->format('d-m-Y')));
        }

        // DescripcionOperacion, required
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:DescripcionOperacion', $invoice->description));

        // FacturaSimplificadaArt7273
        if ($invoice->type == InvoiceType::SIMPLIFIED || ($invoice->type == InvoiceType::RECTIFICATION_SIMPLIFIED)) {
            $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:FacturaSinIdentifDestinatarioArt61d', AEAT::YES));
        } else {
            $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:FacturaSinIdentifDestinatarioArt61d', AEAT::NO));

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
                if ($invoice->isIntracommunityOperation()) {
                    $idOtro->appendChild($dom->createElementNS($namespace, 'sf:ID', $recipient->getId()));
                } else {
                    $idOtro->appendChild($dom->createElementNS($namespace, 'sf:ID', $recipient->id));
                }
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

        if (!$invoice->isVatExemptOperation()) {
            // TipoImpositivo, required for non VAT exempt operations
            $detalleDesglose->appendChild($dom->createElementNS($namespace, 'sf:TipoImpositivo', $invoice->getTaxRate()));
        }

        // BaseImponibleOimporteNoSujeto
        $detalleDesglose->appendChild($dom->createElementNS($namespace, 'sf:BaseImponibleOimporteNoSujeto', $invoice->getTaxableBase()));

        if (!$invoice->isVatExemptOperation()) {
            // CuotaRepercutida, required for non VAT exempt operations
            $detalleDesglose->appendChild($dom->createElementNS($namespace, 'sf:CuotaRepercutida', $invoice->getTaxAmount()));
        }

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
            $encadenamiento->appendChild($dom->createElementNS($namespace, 'sf:PrimerRegistro', AEAT::YES));
        } else {
            // RegistroAnterior
            $previousInvoice = $invoice->getPreviousInvoice();
            $registroAnterior = $dom->createElementNS($namespace, 'sf:RegistroAnterior');
            $encadenamiento->appendChild($registroAnterior);

            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:IDEmisorFactura', $invoice->issuer->id));
            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:NumSerieFactura', $previousInvoice['number']));
            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:FechaExpedicionFactura', $previousInvoice['date']));
            $registroAnterior->appendChild($dom->createElementNS($namespace, 'sf:Huella', $previousInvoice['hash']));
        }

        $sistemaInformatico = self::buildInformationSystem($dom, $namespace, $this->settings->getInformationSystem());

        if ($sistemaInformatico !== false) {
            $registroAlta->appendChild($sistemaInformatico);
        }

        // FechaHoraHusoGenRegistro
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:FechaHoraHusoGenRegistro', $invoice->getTimestamp()));

        // TipoHuella
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:TipoHuella', AEAT::SHA_256));

        // Huella
        $registroAlta->appendChild($dom->createElementNS($namespace, 'sf:Huella', $invoice->hash($invoice->getTimestamp())));

        $this->document = $dom;
        $this->generated = true;

        // TODO return errors
        return $dom;
    }
}
