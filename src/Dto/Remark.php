<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class Remark
{
    public function __construct(
        public ?string $shortText,
        public ?string $longText,
        public ?string $descriptionXml,
    ) {}
}
