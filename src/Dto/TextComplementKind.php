<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

enum TextComplementKind: string
{
    case Owner = 'Owner';
    case Bidder = 'Bidder';
}
