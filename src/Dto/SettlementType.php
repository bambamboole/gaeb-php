<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

enum SettlementType: string
{
    case Accumulated = 'accumulated';
    case Periodic = 'periodic';
}
