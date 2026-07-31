<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class Party
{
    public function __construct(
        public ?string $name = null,
        public ?string $street = null,
        public ?string $zip = null,
        public ?string $city = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $taxNo = null,
    ) {}
}
