<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class GaebFile
{
    public function __construct(
        public GaebInfo $info,
        public ProjectInfo $project,
        public ?BoQ $boq,
        public ?Party $owner,
        public ?Party $contractor,
        public ?AwardData $award,
    ) {}
}
