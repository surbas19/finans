<?php

namespace Tests\Unit;

use App\Services\AIStatement\JsonValidator;
use PHPUnit\Framework\TestCase;

/**
 * Standart ekstre JSON şeması (app/Schemas/bank_statement_schema.json): OpenAI strict Structured Outputs kuralları
 * (her nesnede bütün alanlar zorunlu, additionalProperties=false, desteklenmeyen anahtar yok) ve PHP doğrulayıcısı.
 */
class EkstreSemasiTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function sema(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../app/Schemas/bank_statement_schema.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_strict_kurallarina_uyar(): void
    {
        $nesneler = 0;
        $gez = function (array $sema, string $yol) use (&$gez, &$nesneler): void {
            foreach (['allOf', 'not', 'if', 'then', 'else', 'oneOf', 'dependentRequired', 'dependentSchemas'] as $yasak) {
                $this->assertArrayNotHasKey($yasak, $sema, "{$yol}: strict modda desteklenmez");
            }
            if (in_array('object', (array) ($sema['type'] ?? []), true)) {
                $nesneler++;
                $this->assertFalse($sema['additionalProperties'] ?? true, "{$yol}: additionalProperties false olmalı");
                $this->assertEqualsCanonicalizing(array_keys($sema['properties']), $sema['required'], "{$yol}: bütün alanlar required");
                foreach ($sema['properties'] as $ad => $alt) {
                    $gez($alt, "{$yol}.{$ad}");
                }
            }
            if (isset($sema['items'])) {
                $gez($sema['items'], "{$yol}[]");
            }
            if (isset($sema['enum']) && in_array('null', (array) $sema['type'], true)) {
                $this->assertContains(null, $sema['enum'], "{$yol}: boş olabilen enum null içermeli");
            }
        };
        $gez(self::sema(), 'kök');
        $this->assertGreaterThanOrEqual(9, $nesneler);
    }

    public function test_dogrulayici_gecerliyi_kabul_eder_eksik_ve_yanlis_turu_reddeder(): void
    {
        $islem = [
            'date' => '2026-10-15', 'description' => 'MARKET', 'amount' => 10.5, 'currency' => 'TRY', 'direction' => 'debit', 'transaction_type' => 'purchase',
            'original_currency' => null, 'original_amount' => null, 'balance' => null, 'bonus_earned' => 0.31, 'source_page' => 1, 'source_text' => '15.10.2026 MARKET 10,50',
            'confidence' => 0.9, 'needs_review' => false, 'review_reason' => null,
            'installment_details' => ['number' => null, 'count' => null, 'total_amount' => null, 'remaining_amount' => null],
        ];
        $ozet = array_fill_keys(['previous_balance', 'total_due', 'min_payment', 'total_purchases', 'total_payments', 'total_refunds', 'total_interest', 'total_fees', 'total_taxes', 'total_bonus_used', 'remaining_installments', 'opening_balance', 'closing_balance', 'total_credits', 'total_debits'], null);
        $veri = [
            'bank_name' => null, 'statement_type' => 'credit_card', 'account' => ['label' => null, 'currency' => 'TRY'],
            'period' => ['start_date' => null, 'end_date' => null, 'cut_date' => '2026-11-10', 'due_date' => null],
            'summary' => $ozet, 'transactions' => [$islem], 'installments' => [],
            'bonus_transactions' => [['date' => '2026-11-02', 'description' => 'BONUS KAMPANYA', 'bonus_amount' => 100.0, 'direction' => 'earned', 'source_page' => 1]],
            'page_audit' => [['page_number' => 1, 'has_transaction_table' => true, 'found_count' => 1, 'status' => 'processed']],
            'warnings' => [], 'review_required' => false,
        ];
        $v = new JsonValidator;
        $this->assertSame([], $v->dogrula($veri, self::sema()));

        $eksik = $veri;
        unset($eksik['transactions'][0]['direction']);
        $this->assertSame(['transactions.0.direction: eksik'], $v->dogrula($eksik, self::sema()));
        $yanlis = $veri;
        $yanlis['transactions'][0]['transaction_type'] = 'fee_interest';
        $yanlis['bonus_transactions'][0]['direction'] = 'kazanilan';
        $this->assertSame(['transactions.0.transaction_type: izin verilen değerlerden biri olmalı', 'bonus_transactions.0.direction: izin verilen değerlerden biri olmalı'], $v->dogrula($yanlis, self::sema()));
    }
}
