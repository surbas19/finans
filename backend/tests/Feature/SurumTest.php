<?php

namespace Tests\Feature;

use Tests\TestCase;

class SurumTest extends TestCase
{
    public function test_surum_dosyasi_yoksa_null_doner(): void
    {
        $dosya = base_path('deploy/SURUM');
        $this->assertFileDoesNotExist($dosya, 'Yerelde deploy/SURUM olmamalı (yalnızca pakette).');

        $this->getJson('/api/v1/surum')->assertOk()->assertExactJson(['surum' => null])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_paketteki_surumun_commit_kismini_girissiz_doner(): void
    {
        $dosya = base_path('deploy/SURUM');
        file_put_contents($dosya, "b77c1e8 2026-11-13\n");
        try {
            $this->getJson('/api/v1/surum')->assertOk()->assertExactJson(['surum' => 'b77c1e8']);
        } finally {
            unlink($dosya);
        }
    }
}
