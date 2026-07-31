<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class Contractor
{
    public function __construct(
        public ?string $name,
        public ?string $street,
        public ?string $zip,
        public ?string $city,
        public ?string $email,
        public ?string $phone,
    ) {}
}
