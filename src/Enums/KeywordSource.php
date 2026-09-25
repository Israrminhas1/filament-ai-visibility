<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum KeywordSource: string implements HasColor, HasLabel
{
    case Manual = 'manual';

    case Csv = 'csv';

    case Gsc = 'gsc';

    case DataForSeo = 'dataforseo';

    case SerpApiPaa = 'serpapi_paa';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Csv => 'CSV',
            self::Gsc => 'Search Console',
            self::DataForSeo => 'DataForSEO',
            self::SerpApiPaa => 'People also ask',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Csv => 'gray',
            self::Gsc => 'info',
            self::DataForSeo => 'warning',
            self::SerpApiPaa => 'primary',
        };
    }
}
