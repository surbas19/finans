<?php

namespace App\Enums;

/** Ekstreden okunan satır önce taslaktır; kullanıcı onaylar ya da dışarıda bırakır. Elle girilen satır onaylıdır. */
enum KartIslemDurumu: string
{
    case Taslak = 'taslak';
    case Onayli = 'onayli';
    case Atlandi = 'atlandi';
}
