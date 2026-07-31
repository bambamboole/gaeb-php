<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

enum ChangeOrderStatus: string
{
    case Recognized = 'Recog';
    case Filed = 'Filed';
    case Offered = 'Offered';
    case Withdrawn = 'Withdrawn';
    case Rejected = 'Rejected';
    case ObjectionToRejection = 'ObjToRecj';
    case FormallyAcknowledged = 'FormAckn';
    case Approved = 'Approved';
}
