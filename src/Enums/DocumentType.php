<?php

namespace Mayaram\LaravelOcr\Enums;

enum DocumentType: string
{
    case INVOICE = 'invoice';
    case RECEIPT = 'receipt';
    case CONTRACT = 'contract';
    case PURCHASE_ORDER = 'purchase_order';
    case SHIPPING = 'shipping';
    case GENERAL = 'general';
}
