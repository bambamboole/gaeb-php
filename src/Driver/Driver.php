<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Driver;

use Bambamboole\Gaeb\Dto\GaebFile;

interface Driver
{
    public function supports(string $content): bool;

    public function parse(string $content): GaebFile;
}
