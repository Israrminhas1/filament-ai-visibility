<?php

namespace IsrarMinhas\FilamentAiVisibility\Competitors;

use Illuminate\Support\Facades\DB;
use IsrarMinhas\FilamentAiVisibility\Enums\CompetitorLabel;
use IsrarMinhas\FilamentAiVisibility\Events\CompetitorAccepted;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;
use IsrarMinhas\FilamentAiVisibility\Models\Citation;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;
use IsrarMinhas\FilamentAiVisibility\Models\Model;
use IsrarMinhas\FilamentAiVisibility\Models\ResultMention;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;

/**
 * What a user can do with a discovered candidate.
 */
class CandidateActions
{
    /**
     * Track the candidate as a competitor. Past answers are updated too, so
     * share of voice includes it from the start.
     *
     * @throws \IsrarMinhas\FilamentAiVisibility\Exceptions\LimitExceeded
     */
    public function accept(Candidate $candidate): Competitor
    {
        $brand = $candidate->brand;
        app(Limits::class)->ensureCanAddCompetitor($brand);

        $classification = $candidate->latestClassification;
        $name = $classification?->company_name ?: $candidate->name;
        $created = false;

        $competitor = DB::transaction(function () use ($candidate, $brand, $name, &$created) {
            // Re-read under a lock, so a double click or two users cannot add it twice.
            $current = Candidate::query()->whereKey($candidate->getKey())->lockForUpdate()->first();

            if (! $current || ! $current->isOpen()) {
                $existing = $current?->status === Candidate::STATUS_ACCEPTED && $current->competitor_id
                    ? Competitor::query()->find($current->competitor_id)
                    : null;

                if ($existing) {
                    $candidate->setRawAttributes($current->getAttributes(), true);

                    return $existing;
                }

                throw new CandidateNotOpen("{$candidate->name} was already " . strtolower(Candidate::statuses()[$current?->status] ?? 'removed') . '.');
            }

            $competitor = $brand->competitors()->create([
                'name' => $name,
                'aliases' => array_values(array_filter([
                    $candidate->kind === 'name' && mb_strtolower($candidate->name) !== mb_strtolower($name) ? $candidate->name : null,
                ])),
                'domains' => array_filter([$candidate->domain]),
                'category' => ($candidate->label ?? CompetitorLabel::DirectCompetitor)->value,
                'source' => 'discovered',
            ]);

            $candidate->forceFill(['status' => Candidate::STATUS_ACCEPTED, 'competitor_id' => $competitor->getKey()])->save();

            $this->backfill($candidate, $competitor);
            $created = true;

            return $competitor;
        });

        if ($created) {
            CompetitorAccepted::dispatch($candidate, $competitor);
        }

        return $competitor;
    }

    public function reject(Candidate $candidate): void
    {
        $candidate->forceFill(['status' => Candidate::STATUS_REJECTED])->save();
    }

    /**
     * Never suggest this candidate again.
     */
    public function ignore(Candidate $candidate): void
    {
        $candidate->forceFill(['status' => Candidate::STATUS_IGNORED])->save();

        // A name candidate carries its merged domain; ignore that too, or it comes back as "domain:x".
        if ($candidate->kind === 'name' && $candidate->domain) {
            $companion = Candidate::query()->firstOrNew([
                'brand_id' => $candidate->brand_id,
                'key' => 'domain:' . $candidate->domain,
            ]);

            $companion->forceFill([
                'tenant_id' => $companion->tenant_id ?? $candidate->tenant_id,
                'kind' => $companion->kind ?? 'domain',
                'name' => $companion->name ?? $candidate->domain,
                'domain' => $candidate->domain,
                'status' => $companion->status === Candidate::STATUS_ACCEPTED ? Candidate::STATUS_ACCEPTED : Candidate::STATUS_IGNORED,
            ])->save();
        }
    }

    public function reopen(Candidate $candidate): void
    {
        $candidate->forceFill(['status' => $candidate->label ? Candidate::STATUS_CLASSIFIED : Candidate::STATUS_NEW])->save();
    }

    /**
     * Correct the label. Corrections are shown to the classifier as examples next time.
     */
    public function relabel(Candidate $candidate, CompetitorLabel $label, ?int $userId = null): void
    {
        $classification = $candidate->latestClassification ?? $candidate->classifications()->create([
            'label' => $candidate->label ?? $label,
            'confidence' => 'low',
        ]);

        $classification->forceFill([
            'override_label' => $label,
            'overridden_by' => $userId,
            'overridden_at' => now(),
        ])->save();

        $candidate->forceFill([
            'label' => $label,
            'confidence' => 'high',
            'status' => $candidate->isOpen() ? Candidate::STATUS_CLASSIFIED : $candidate->status,
            'classified_at' => now(),
        ])->save();

        $this->applySourceCategory($candidate);
    }

    /**
     * Use the label to categorise past citations of this domain that had no category.
     */
    public function applySourceCategory(Candidate $candidate): void
    {
        $category = $candidate->label?->sourceCategory();

        if (! $candidate->domain || ! $category || $category === 'competitor' || $category === 'own') {
            return;
        }

        Citation::query()
            ->where('domain', $candidate->domain)
            ->where('category', 'other')
            ->whereIn('result_id', $candidate->brand->results()->select('id'))
            ->update(['category' => $category]);
    }

    /**
     * Link the brand's past citations and name mentions to the new competitor.
     */
    protected function backfill(Candidate $candidate, Competitor $competitor): void
    {
        $results = $candidate->brand->results()->select(Model::prefixedTable('results') . '.id');

        if ($candidate->domain) {
            Citation::query()
                ->whereIn('result_id', $results)
                ->where('domain', $candidate->domain)
                ->update(['competitor_id' => $competitor->getKey(), 'category' => 'competitor']);
        }

        ResultMention::query()
            ->whereIn('result_id', $results)
            ->where('subject_type', 'entity')
            ->whereRaw('LOWER(name_matched) IN (' . implode(',', array_fill(0, count($competitor->names()), '?')) . ')', array_map('mb_strtolower', $competitor->names()))
            ->update(['subject_type' => 'competitor', 'subject_id' => $competitor->getKey()]);
    }
}
