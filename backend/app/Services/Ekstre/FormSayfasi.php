<?php

namespace App\Services\Ekstre;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Header;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\PDFObject;
use Smalot\PdfParser\XObject\Form;

/**
 * Sayfanın içine çizilen "Form XObject"in metnini konumlu okumak için (bazı bankalar — ör. Garanti BBVA — ilk sayfayı
 * böyle üretir). smalot `Form::getDataTm()` sayfa gibi `Contents` arar ve bulamaz; burada formun kendi akışı okunur.
 * Yazı tipleri formun `Resources`'ından gelir (Page::getFonts).
 */
class FormSayfasi extends Form
{
    public static function formdan(Form $form, Document $belge, ?Config $ayar): self
    {
        return new self($belge, $form->getHeader(), $form->getContent(), $ayar);
    }

    /** `getDataTm` metinleri buradan alır; sayfa sürümü `Contents` arar, form için kendi akışı */
    public function getTextArray(?Page $page = null): array
    {
        $icerik = new PDFObject($this->document, new Header([], $this->document), $this->content, $this->config);

        return $icerik->getTextArray($this);
    }

    public function extractRawData(): array
    {
        $sonuc = [];
        foreach ($this->getSectionsText($this->getContent()) as $bolum) {
            foreach ($this->getCommandsText($bolum) as $komut) {
                $sonuc[] = $komut;
            }
        }

        return $sonuc;
    }

    /** Formun ham akışı iki ayrı adla aynı nesne olabilir; aynı içerik bir kez okunur */
    public function ozet(): string
    {
        return hash('crc32b', (string) $this->getContent());
    }

    public static function bos(Document $belge, ?Header $baslik, ?Config $ayar): self
    {
        return new self($belge, $baslik, '', $ayar);
    }
}
