<?php

namespace Tests\Unit;

use App\Services\AIStatement\DuplicateDetector;
use App\Services\AIStatement\TransactionValidator;
use PHPUnit\Framework\TestCase;

/**
 * Yapay zekâ satır doğrulaması (kredi kartı): ret ve inceleme kuralları, sayfa metnine göre mükerrer ayıklama.
 */
class IslemDogrulayiciTest extends TestCase
{
    private function islem(string $kaynak, float $tutar, array $ek = []): array
    {
        return [
            'date' => '2026-10-15', 'description' => 'MAGAZA', 'amount' => $tutar, 'currency' => 'TRY', 'direction' => 'debit', 'transaction_type' => 'purchase',
            'original_currency' => null, 'original_amount' => null, 'balance' => null, 'bonus_earned' => null, 'source_page' => 1, 'source_text' => $kaynak,
            'confidence' => 0.95, 'needs_review' => false, 'review_reason' => null,
            'installment_details' => ['number' => null, 'count' => null, 'total_amount' => null, 'remaining_amount' => null], ...$ek,
        ];
    }

    private function dogrula(array $islemler, array $sayfalar): array
    {
        return (new TransactionValidator(new DuplicateDetector))->dogrula($islemler, $sayfalar);
    }

    public function test_ret_ve_inceleme_kurallari(): void
    {
        $sayfa = implode("\n", [
            '15.10.2026 MIGROS KADIKOY SUBE 1.234,56',
            '03.11.2026 SHOP 756,00x4=3.024,00 1.Taksit 2,72 756,00',
            '05.11.2026 MAGAZA 300,00x3=900,00 2.Taksit 1,53 300,00',
            'ONCEKI DONEMDEN DEVIR EDILEN TUTAR 65.226,96',
            '20.10.2026 ODEMENIZ ICIN TESEKKUR EDERIZ 5.000,00+',
        ]);
        $sonuc = $this->dogrula([
            $this->islem('15.10.2026 MIGROS KADIKOY 1.234,56', 1234.56),                       // benzer (SUBE eksik) → inceleme
            $this->islem('16.10.2026 HAYALI MAGAZA 99,00', 99.0),                              // uydurma → ret
            $this->islem('ONCEKI DONEMDEN DEVIR EDILEN TUTAR 65.226,96', 65226.96),           // özet → ret
            $this->islem('03.11.2026 SHOP 756,00x4=3.024,00 1.Taksit 2,72 756,00', 756.0),     // en sağdaki: temiz
            $this->islem('05.11.2026 MAGAZA 300,00x3=900,00 2.Taksit 1,53 300,00', 1.53),     // bonus sütunu seçilmiş → inceleme
            $this->islem('20.10.2026 ODEMENIZ ICIN TESEKKUR EDERIZ 5.000,00+', 5000.0, ['transaction_type' => 'payment']), // ödeme ama debit → inceleme
            $this->islem('15.10.2026 MIGROS KADIKOY SUBE 1.234,56', 1234.56, ['needs_review' => true, 'review_reason' => 'Silik', 'confidence' => 0.5]),
        ], [$sayfa]);

        $this->assertSame([
            'Kaynak satır, belirtilen sayfada bulunamadı (uydurma olabilir).',
            'Özet satırı (devreden borç, toplam, asgari ödeme): işlem değil.',
        ], array_column($sonuc['reddedilen'], 'neden'));
        $uyarilar = array_map(fn (array $s): array => array_slice($s['okuma']['uyarilar'], 1), $sonuc['kabul']);
        $this->assertSame([
            ['Kaynak satır sayfada birebir yok; benzer satır bulundu.'],
            [],
            ['Satırda birden çok tutar var; seçilen tutar en sağdaki değil.'],
            ['Tür ile yön uyuşmuyor (payment / debit).'],
            ['Yapay zekâ: Silik', 'Düşük güven (0.5).'],
        ], $uyarilar);
        $this->assertSame([true, false, true, true, true], array_map(fn (array $s): bool => $s['okuma']['inceleme'], $sonuc['kabul']));
        $this->assertSame(['harcama', 'harcama', 'harcama', 'odeme', 'harcama'], array_column($sonuc['kabul'], 'tur'));
    }

    public function test_taranmis_sayfa_satiri_incelemeye_duser(): void
    {
        $sonuc = $this->dogrula([$this->islem('15.10.2026 MIGROS 100,00', 100.0, ['date' => null])], ['']);
        $this->assertCount(1, $sonuc['kabul']);
        $this->assertSame(['islem_tarihi'], $sonuc['kabul'][0]['okuma']['alanlar']);
        $this->assertContains('Sayfada metin yok: PDF görüntüsünden okundu.', $sonuc['kabul'][0]['okuma']['uyarilar']);
        $this->assertTrue($sonuc['kabul'][0]['okuma']['inceleme']);
    }

    public function test_ayni_gun_ayni_tutar_ayri_satirlar_korunur_model_tekrari_ayiklanir(): void
    {
        // Sayfada aynı satır iki kez (aynı gün iki kez aynı benzin): ikisi de işlem
        $iki = "16.10.2026 PETROL 1.600,00\n16.10.2026 PETROL 1.600,00\n17.10.2026 MARKET 50,00";
        $sonuc = $this->dogrula([
            $this->islem('16.10.2026 PETROL 1.600,00', 1600.0),
            $this->islem('16.10.2026 PETROL 1.600,00', 1600.0),
            $this->islem('17.10.2026 MARKET 50,00', 50.0),
            $this->islem('17.10.2026 MARKET 50,00', 50.0), // sayfada bir kez: model tekrarı
        ], [$iki]);
        $this->assertCount(3, $sonuc['kabul']);
        $this->assertSame(['Aynı kaynak satır sayfada 1 kez geçiyor; fazla tekrar alınmadı (mükerrer).'], array_column($sonuc['reddedilen'], 'neden'));
    }
}
