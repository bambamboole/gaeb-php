<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

enum MarkupType: string
{
    case IdenticalMarker = 'IdentAsMark';
    case AllInCategory = 'AllInCat';
    case ListedSubQuantities = 'ListInSubQty';
}
