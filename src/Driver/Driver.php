<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Driver;

use Bambamboole\GaebParser\Dto\GaebFile;

interface Driver
{
    public function supports(string $content): bool;

    public function parse(string $content): GaebFile;
}
