<?php

namespace Mayaram\LaravelOcr\Enums;

enum OcrDriver: string
{
    case TESSERACT = 'tesseract';
    case GOOGLE_VISION = 'google_vision';
    case AWS_TEXTRACT = 'aws_textract';
    case AZURE = 'azure';
}
