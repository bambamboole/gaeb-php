<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class Party
{
    public function __construct(
        public ?string $name,
        public ?string $street,
        public ?string $zip,
        public ?string $city,
        public ?string $phone,
        public ?string $email,
        public ?string $taxNo,
    ) {}
}
