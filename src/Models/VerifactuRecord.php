<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuRecordType;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuRecordVariant;

/**
 * @property int $id
 * @property ?int $previous_record_id
 * @property ?string $registry_scope
 * @property string $issuer_nif
 * @property ?string $issuer_name
 * @property string $invoice_number
 * @property Carbon $invoice_date
 * @property VerifactuRecordType $record_type
 * @property ?VerifactuRecordVariant $record_variant
 * @property ?InvoiceType $invoice_type
 * @property ?string $recipient_name
 * @property ?string $recipient_id
 * @property ?string $recipient_country_code
 * @property ?IdType $recipient_id_type
 * @property ?string $status
 * @property ?string $hash
 * @property ?string $previous_hash
 * @property ?string $request_xml
 * @property ?array<string, mixed> $invoice_payload
 */
class VerifactuRecord extends Model
{
    protected $table = 'verifactu_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'record_type' => VerifactuRecordType::class,
            'record_variant' => VerifactuRecordVariant::class,
            'invoice_type' => InvoiceType::class,
            'recipient_id_type' => IdType::class,
            'invoice_payload' => 'array',
            'response_json' => 'array',
            'signed_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function getPreviousRecord(): ?self
    {
        return self::previousForChain($this->issuer_nif, $this->registry_scope);
    }

    public function getPreviousRecordId(): ?int
    {
        $record = $this->getPreviousRecord();

        return $record === null ? null : (int) $record->getKey();
    }

    public function getPreviousHash(): ?string
    {
        return $this->getPreviousRecord()?->hash;
    }

    public static function previousForChain(string $issuerNif, ?string $registryScope = null): ?self
    {
        if (trim($issuerNif) === '') {
            return null;
        }

        return self::query()
            ->forRegistryChain($issuerNif, $registryScope)
            ->whereNotNull('hash')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForRegistryChain(Builder $query, string $issuerNif, ?string $registryScope = null): Builder
    {
        return $query
            ->where('registry_scope', $registryScope)
            ->where('issuer_nif', $issuerNif);
    }
}
