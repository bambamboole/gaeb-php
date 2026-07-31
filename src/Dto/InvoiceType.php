<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

enum InvoiceType: string
{
    case Deduction = 'deduction';
    case FinalAccount = 'final account';
    case PartFinalAccount = 'part final account';
    case AdvancePayment = 'advance payment';
    case SingleInvoice = 'single invoice';
    case ProFormaInvoice = 'pro forma invoice';
    case ReviewedInvoice = 'reviewed invoice';
}
