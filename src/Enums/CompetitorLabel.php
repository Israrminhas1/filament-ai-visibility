<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum CompetitorLabel: string implements HasColor, HasDescription, HasLabel
{
    case DirectCompetitor = 'direct_competitor';

    case IndirectCompetitor = 'indirect_competitor';

    case Marketplace = 'marketplace';

    case ReviewComparison = 'review_comparison';

    case MediaPublisher = 'media_publisher';

    case ForumCommunity = 'forum_community';

    case Directory = 'directory';

    case ToolOrService = 'tool_or_service';

    case SupplierPartner = 'supplier_partner';

    case OwnProperty = 'own_property';

    case Unrelated = 'unrelated';

    public function getLabel(): string
    {
        return match ($this) {
            self::DirectCompetitor => 'Direct competitor',
            self::IndirectCompetitor => 'Indirect competitor',
            self::Marketplace => 'Marketplace',
            self::ReviewComparison => 'Review / comparison',
            self::MediaPublisher => 'Media / publisher',
            self::ForumCommunity => 'Forum / community',
            self::Directory => 'Directory',
            self::ToolOrService => 'Related tool or service',
            self::SupplierPartner => 'Supplier / partner',
            self::OwnProperty => 'Your own property',
            self::Unrelated => 'Unrelated',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::DirectCompetitor => 'Customers could choose it instead of the brand for the same core need.',
            self::IndirectCompetitor => 'Solves the same problem differently, or serves an adjacent segment.',
            self::Marketplace => 'Lets customers buy or book the same kind of offering from many sellers.',
            self::ReviewComparison => 'Reviews, comparisons and "best X" lists.',
            self::MediaPublisher => 'News, blogs and magazines.',
            self::ForumCommunity => 'Forums, Q&A sites and communities.',
            self::Directory => 'Listings without transactions.',
            self::ToolOrService => 'A related tool that does not provide the core offering.',
            self::SupplierPartner => 'Sells to the brand, integrates with it, or partners with it.',
            self::OwnProperty => 'Belongs to the brand itself (another domain, subsidiary, product).',
            self::Unrelated => 'No meaningful relation to the brand.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DirectCompetitor => 'danger',
            self::IndirectCompetitor => 'warning',
            self::OwnProperty => 'success',
            self::ReviewComparison, self::Marketplace, self::MediaPublisher, self::ForumCommunity => 'info',
            default => 'gray',
        };
    }

    public function isCompetitor(): bool
    {
        return in_array($this, [self::DirectCompetitor, self::IndirectCompetitor], true);
    }

    /**
     * The source category a cited domain with this label belongs to, if any.
     */
    public function sourceCategory(): ?string
    {
        return match ($this) {
            self::ReviewComparison => 'review_comparison',
            self::Marketplace => 'marketplace',
            self::MediaPublisher => 'media_publisher',
            self::ForumCommunity => 'forum_community',
            self::DirectCompetitor, self::IndirectCompetitor => 'competitor',
            self::OwnProperty => 'own',
            default => null,
        };
    }
}
