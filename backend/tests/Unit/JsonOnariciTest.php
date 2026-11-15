<?php

namespace Tests\Unit;

use App\Services\AIStatement\JsonOnarici;
use PHPUnit\Framework\TestCase;

/**
 * Sohbet arayüzü artıklarının onarımı: yalnızca biçim; geçerli JSON kaçışları ve değerler bozulmaz.
 */
class JsonOnariciTest extends TestCase
{
    private JsonOnarici $onarici;

    protected function setUp(): void
    {
        $this->onarici = new JsonOnarici;
    }

    /**
     * @return array{0: mixed, 1: ?string}
     */
    private function coz(string $metin): array
    {
        $sonuc = $this->onarici->onar($metin);

        return [json_decode($sonuc['metin'], true), $sonuc['duzeltme']];
    }

    public function test_markdown_kacislari_atilir_gecerli_kacislar_korunur(): void
    {
        [$veri, $duzeltme] = $this->coz('{"a": "HEPSIPAY \*HEPSIBURADA \_x\_ \#1", "b": "tırnak \" ters \\\\ \/ \n satır \u00e7"}');
        $this->assertSame('HEPSIPAY *HEPSIBURADA _x_ #1', $veri['a']);
        $this->assertSame("tırnak \" ters \\ / \n satır ç", $veri['b']);
        $this->assertStringContainsString('otomatik temizlendi', (string) $duzeltme);
    }

    public function test_cift_ters_egik_cizgiyi_ve_ardindan_yildizi_dogru_tuketir(): void
    {
        // JSON'da geçerli: `\\` ters eğik çizgi, ardından normal `*`; onarım dokunmaz
        $sonuc = $this->onarici->onar('{"a": "x\\\\*y"}');
        $this->assertNull($sonuc['duzeltme']);
        $this->assertSame('x\\*y', json_decode($sonuc['metin'], true)['a']);
    }

    public function test_html_bosluk_satir_sonu_ters_egik_cizgi_ve_gorunmez_karakterler(): void
    {
        $metin = "{\\\n&#x20;&#x20;\"a\": 1,\\\n&nbsp;\"b\":\u{00A0}\"x\u{200B}y\"\\\n}";
        [$veri, $duzeltme] = $this->coz($metin);
        $this->assertSame(['a' => 1, 'b' => 'xy'], $veri);
        $this->assertNotNull($duzeltme);
    }

    public function test_temiz_json_dokunulmaz(): void
    {
        $sonuc = $this->onarici->onar('{"a": "düz metin", "b": [1, 2]}');
        $this->assertNull($sonuc['duzeltme']);
        $this->assertSame('{"a": "düz metin", "b": [1, 2]}', $sonuc['metin']);
    }

    public function test_hata_konumu_neden_ve_yeri_soyler(): void
    {
        $this->assertStringContainsString('sondaki virgül', (string) $this->onarici->hataKonumu('{"a": 1,}'));
        $this->assertStringContainsString('satır 1, sütun 9', (string) $this->onarici->hataKonumu('{"a": 1,}'));
        $this->assertStringContainsString('açık parantez', (string) $this->onarici->hataKonumu("{\n \"a\": [1, 2,\n"));
        $this->assertStringContainsString('tırnak içinde satır sonu', (string) $this->onarici->hataKonumu("{\"a\": \"x\ny\"}"));
        $this->assertStringContainsString('fazladan metin', (string) $this->onarici->hataKonumu('{"a": 1} bitti'));
        $this->assertStringContainsString('geçersiz ters eğik çizgi (\\*)', (string) $this->onarici->hataKonumu('{"a": "x \*y"}'));
        $this->assertStringContainsString('satır 2', (string) $this->onarici->hataKonumu("{\n \"a\": \"\\q\"}"));
        $this->assertNull($this->onarici->hataKonumu('{"a": 1}'));
    }
}
