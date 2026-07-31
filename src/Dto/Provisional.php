<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

enum Provisional: string
{
    case WithoutTotal = 'WithoutTotal';
    case WithTotal = 'WithTotal';
}
