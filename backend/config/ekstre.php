<?php

// Ekstre yükleme ve yapay zekâ ile analiz ayarları (docs/CANLI.md)
return [
    // Yüklenebilecek en büyük PDF (MB); sunucunun upload_max_filesize / post_max_size değerleri de yetmeli
    'max_mb' => (int) env('EKSTRE_MAX_MB', 10),

    // Yapay zekâya gönderilebilecek en büyük PDF (MB): base64 + JSON bellekte ~4 katı yer kaplar
    'ai_max_mb' => (int) env('EKSTRE_AI_MAX_MB', 8),

    // Yapay zekâ taslağı onaylanıp Bütçe'ye aktarılabilir mi (testler geçene kadar kapalı tutuldu)
    'ai_aktarim' => (bool) env('EKSTRE_AI_AKTARIM', false),
];
