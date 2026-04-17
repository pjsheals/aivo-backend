<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianBrandPackageGenerator — Module 9
 *
 * Assembles per-platform Brand Intelligence Package JSON files from:
 *   - Approved atoms (grouped by probe_type: awareness / decision_stage / spontaneous_consideration)
 *   - Verified evidence chain
 *   - M1 displacement map per platform
 *   - M2 brand context
 *
 * Produces five files per brand:
 *   {brand-slug}-chatgpt.json
 *   {brand-slug}-gemini.json
 *   {brand-slug}-perplexity.json
 *   {brand-slug}-claude.json
 *   {brand-slug}-grok.json
 *
 * Stored in meridian_brand_packages (one row per brand per platform, upserted).
 * Served for deployment to /.well-known/llm/ on the client's domain.
 *
 * Retrieval timing:
 *   Perplexity → immediate live retrieval effect on deployment
 *   Grok       → live web retrieval, recency-weighted
 *   ChatGPT / Gemini / Claude → 8–16 week training corpus cycle
 */
class MeridianBrandPackageGenerator
{
    public const PLATFORMS = ['chatgpt', 'gemini', 'perplexity', 'claude', 'grok'];

    /**
     * Atom model_variant values that apply to each platform.
     * claude and grok have no dedicated variant; they receive universal atoms.
     */
    private const ATOM_VARIANT_MAP = [
        'chatgpt'    => ['chatgpt', 'universal'],
        'gemini'     => ['gemini',  'universal'],
        'perplexity' => ['perplexity', 'universal'],
        'claude'     => ['universal'],
        'grok'       => ['universal'],
    ];

    private const PLATFORM_WEIGHTINGS = [
        'gemini' => [
            'primary_authority'    => 'peer_reviewed_clinical',
            'secondary_authority'  => 'academic_doi',
            'retrieval_type'       => 'training_corpus',
            'training_cycle_weeks' => '8-16',
            'fingerprint'          => 'deterministic_educational_drift',
        ],
        'chatgpt' => [
            'primary_authority'    => 'training_corpus_depth',
            'secondary_authority'  => 'permanence',
            'retrieval_type'       => 'training_corpus',
            'training_cycle_weeks' => '8-16',
            'fingerprint'          => 'probabilistic_decision_boundary',
        ],
        'perplexity' => [
            'primary_authority'    => 'live_retrieval',
            'secondary_authority'  => 'recency',
            'retrieval_type'       => 'live_rag',
            'training_cycle_weeks' => null,
            'fingerprint'          => 'live_rag_retrieval_recovery',
            'note'                 => 'Immediate effect on deployment. No training cycle.',
        ],
        'claude' => [
            'primary_authority'    => 'structured_data',
            'secondary_authority'  => 'citation_chain',
            'retrieval_type'       => 'training_corpus',
            'training_cycle_weeks' => '8-16',
            'fingerprint'          => 'structured_reasoning',
        ],
        'grok' => [
            'primary_authority'    => 'recent_web',
            'secondary_authority'  => 'social_signals',
            'retrieval_type'       => 'live_web',
            'training_cycle_weeks' => null,
            'fingerprint'          => 'real_time_retrieval',
            'note'                 => 'Live web retrieval. Recency and social signal weighted.',
        ],
    ];

    private const AUTHORITY_INDEX = [
        'zenodo'      => '10.5281/zenodo.19613989',
        'github'      => 'https://github.com/pjsheals/aivo-research',
        'huggingface' => 'https://huggingface.co/aivo-research',
        'standard'    => 'AIVO Evidentia Filter Taxonomy WP-2026-01',
        'publisher'   => 'AIVO Research Intelligence Platform',
    ];

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    public function generate(int $brandId, int $auditId, int $agencyId): array
    {
        $brand = DB::table('meridian_brands')->find($brandId);
        if (!$brand) throw new \RuntimeException("Brand {$brandId} not found.");

        $audit = DB::table('meridian_audits')->find($auditId);
        if (!$audit) throw new \RuntimeException("Audit {$auditId} not found.");

        // All approved atoms for this brand (any audit)
        $allAtoms = DB::table('meridian_atoms')
            ->where('brand_id', $brandId)
            ->where('approval_status', 'approved')
            ->get();

        // Verified evidence chain — ordered highest authority first
        $evidence = DB::table('meridian_evidence_submissions')
            ->where('brand_id', $brandId)
            ->where('verification_status', 'verified')
            ->orderByDesc('authority_score')
            ->get();

        // M1 classifications for this audit, grouped by platform
        $classifications = DB::table('meridian_filter_classifications')
            ->where('audit_id', $auditId)
            ->where('brand_id', $brandId)
            ->orderBy('created_at')
            ->get()
            ->groupBy('platform');

        // M2 brand context for this brand/audit, keyed by variant
        $contextRows = DB::table('meridian_brand_context')
            ->where('brand_id', $brandId)
            ->where('audit_id', $auditId)
            ->get()
            ->keyBy('variant');

        $results = [];

        foreach (self::PLATFORMS as $platform) {
            $package = $this->buildPackage(
                $platform,
                $brand,
                $audit,
                $allAtoms,
                $evidence,
                $classifications,
                $contextRows
            );

            $atomCount     = count($package['awareness_atoms'])
                           + count($package['decision_stage_atoms'])
                           + count($package['spontaneous_consideration_atoms']);
            $evidenceCount = count($package['evidence_chain']);

            $this->savePackage($brandId, $agencyId, $platform, $package, $atomCount, $evidenceCount);

            $results[] = [
                'platform'       => $platform,
                'atom_count'     => $atomCount,
                'evidence_count' => $evidenceCount,
                'generated_at'   => now(),
            ];
        }

        return [
            'brand_id'   => $brandId,
            'brand_name' => $brand->name,
            'audit_id'   => $auditId,
            'platforms'  => $results,
        ];
    }

    // -------------------------------------------------------------------------
    // Status — current package state across all five platforms
    // -------------------------------------------------------------------------

    public function status(int $brandId, int $agencyId): array
    {
        $rows = DB::table('meridian_brand_packages')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId)
            ->orderBy('platform')
            ->get();

        $packages = [];
        $platformsFound = [];

        foreach ($rows as $row) {
            $platformsFound[] = $row->platform;
            $packages[] = [
                'platform'       => $row->platform,
                'atom_count'     => (int)$row->atom_count,
                'evidence_count' => (int)$row->evidence_count,
                'version'        => $row->version,
                'generated_at'   => $row->generated_at,
            ];
        }

        // Fill in ungenerated platforms
        foreach (self::PLATFORMS as $platform) {
            if (!in_array($platform, $platformsFound, true)) {
                $packages[] = [
                    'platform'       => $platform,
                    'atom_count'     => 0,
                    'evidence_count' => 0,
                    'version'        => null,
                    'generated_at'   => null,
                ];
            }
        }

        usort($packages, static fn($a, $b) => strcmp($a['platform'], $b['platform']));

        return [
            'brand_id'  => $brandId,
            'packages'  => $packages,
            'generated' => count($platformsFound),
            'total'     => count(self::PLATFORMS),
        ];
    }

    // -------------------------------------------------------------------------
    // Download — raw JSON content for one platform
    // -------------------------------------------------------------------------

    public function download(int $brandId, int $agencyId, string $platform): ?array
    {
        $row = DB::table('meridian_brand_packages')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId)
            ->where('platform', $platform)
            ->first();

        if (!$row) return null;

        return json_decode($row->file_content, true);
    }

    // -------------------------------------------------------------------------
    // Per-platform package builder
    // -------------------------------------------------------------------------

    private function buildPackage(
        string $platform,
        object $brand,
        object $audit,
        object $allAtoms,
        object $evidence,
        object $classifications,
        object $contextRows
    ): array {
        $brandSlug       = $this->brandSlug($brand->name);
        $allowedVariants = self::ATOM_VARIANT_MAP[$platform];

        // Filter atoms to those applicable for this platform
        $platformAtoms = $allAtoms->filter(
            static fn($a) => in_array($a->model_variant ?? 'universal', $allowedVariants, true)
        );

        return [
            'brand'            => $brand->name,
            'brand_slug'       => $brandSlug,
            'platform_variant' => $platform,
            'version'          => '1.0',
            'generated'        => date('Y-m-d'),
            'generated_at'     => now(),
            'file_path'        => "/.well-known/llm/{$brandSlug}-{$platform}.json",

            'displacement_map' => $this->buildDisplacementMap(
                $platform,
                $classifications->get($platform, collect())
            ),

            'brand_context' => $this->extractBrandContext($platform, $contextRows),

            'awareness_atoms' => $this->formatAtoms(
                $platformAtoms->filter(static fn($a) => ($a->probe_type ?? '') === 'awareness')
            ),
            'decision_stage_atoms' => $this->formatAtoms(
                $platformAtoms->filter(static fn($a) => ($a->probe_type ?? '') === 'decision_stage')
            ),
            'spontaneous_consideration_atoms' => $this->formatAtoms(
                $platformAtoms->filter(static fn($a) => ($a->probe_type ?? '') === 'spontaneous_consideration')
            ),

            'evidence_chain'   => $this->buildEvidenceChain($evidence),
            'conversational_qa' => $this->buildConversationalQa($platformAtoms),

            'authority_index'    => self::AUTHORITY_INDEX,
            'platform_weighting' => self::PLATFORM_WEIGHTINGS[$platform],
        ];
    }

    // -------------------------------------------------------------------------
    // Displacement map — worst-case gap (earliest dit_turn) for this platform
    // -------------------------------------------------------------------------

    private function buildDisplacementMap(string $platform, object $classificationRows): array
    {
        if ($classificationRows->isEmpty()) {
            return [
                'platform'               => $platform,
                'displacement_turn'      => null,
                'handoff_turn'           => null,
                'survival_gap'           => null,
                'displacement_criteria'  => null,
                'displacing_brand'       => null,
                'primary_filter'         => null,
                'probe_type'             => null,
                'displacement_mechanism' => null,
                'note'                   => 'No M1 classification data for this platform.',
            ];
        }

        // Earliest displacement turn = worst-case gap
        $worst = $classificationRows->sortBy(static fn($c) => $c->dit_turn ?? PHP_INT_MAX)->first();

        return [
            'platform'               => $platform,
            'displacement_turn'      => $worst->dit_turn     !== null ? (int)$worst->dit_turn     : null,
            'handoff_turn'           => $worst->handoff_turn !== null ? (int)$worst->handoff_turn : null,
            'survival_gap'           => $worst->survival_gap !== null ? (int)$worst->survival_gap : null,
            'displacement_criteria'  => $worst->displacement_criteria   ?? null,
            'displacing_brand'       => $worst->t4_winner               ?? null,
            'primary_filter'         => $worst->primary_filter          ?? null,
            'probe_type'             => $worst->probe_type               ?? null,
            'displacement_mechanism' => $worst->displacement_mechanism  ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Brand context — platform-specific variant, fallback to universal
    // -------------------------------------------------------------------------

    private function extractBrandContext(string $platform, object $contextRows): ?array
    {
        foreach ([$platform, 'universal'] as $variant) {
            if ($contextRows->has($variant)) {
                $content = $contextRows->get($variant)->file_content;
                return is_string($content) ? json_decode($content, true) : (array)$content;
            }
        }

        // Last resort: first available variant
        $first = $contextRows->first();
        if ($first) {
            $content = $first->file_content;
            return is_string($content) ? json_decode($content, true) : (array)$content;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Atom formatter
    // -------------------------------------------------------------------------

    private function formatAtoms(object $atoms): array
    {
        $result = [];
        foreach ($atoms as $atom) {
            $result[] = [
                'id'                    => $atom->id,
                'filter_type'           => $atom->filter_type,
                'model_variant'         => $atom->model_variant  ?? 'universal',
                'entity'                => $atom->entity,
                'claim'                 => $atom->claim,
                'conversational_query'  => $atom->conversational_query,
                'conversational_answer' => $atom->conversational_answer,
                'reasoning_stage'       => $atom->reasoning_stage,
                'citations'             => json_decode($atom->citations ?? '[]', true),
                'zenodo_doi'            => $atom->zenodo_doi,
                'approved_at'           => $atom->approved_at,
            ];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Evidence chain
    // -------------------------------------------------------------------------

    private function buildEvidenceChain(object $evidence): array
    {
        $chain = [];
        foreach ($evidence as $e) {
            $chain[] = [
                'id'              => $e->id,
                'filter_type'     => $e->filter_type,
                'source_type'     => $e->source_type,
                'source_url'      => $e->source_url,
                'source_title'    => $e->source_title,
                'doi'             => $e->doi,
                'authority_score' => (int)$e->authority_score,
                'date_published'  => $e->date_published ?? null,
            ];
        }
        return $chain;
    }

    // -------------------------------------------------------------------------
    // Conversational Q&A — all atoms with both query and answer
    // -------------------------------------------------------------------------

    private function buildConversationalQa(object $atoms): array
    {
        $qa = [];
        foreach ($atoms as $atom) {
            if (!empty($atom->conversational_query) && !empty($atom->conversational_answer)) {
                $qa[] = [
                    'query'       => $atom->conversational_query,
                    'answer'      => $atom->conversational_answer,
                    'filter_type' => $atom->filter_type,
                    'probe_type'  => $atom->probe_type ?? 'decision_stage',
                ];
            }
        }
        return $qa;
    }

    // -------------------------------------------------------------------------
    // DB upsert — one row per brand_id + agency_id + platform
    // -------------------------------------------------------------------------

    private function savePackage(
        int    $brandId,
        int    $agencyId,
        string $platform,
        array  $package,
        int    $atomCount,
        int    $evidenceCount
    ): void {
        $existing = DB::table('meridian_brand_packages')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId)
            ->where('platform', $platform)
            ->first();

        $data = [
            'brand_id'       => $brandId,
            'agency_id'      => $agencyId,
            'platform'       => $platform,
            'file_content'   => json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'atom_count'     => $atomCount,
            'evidence_count' => $evidenceCount,
            'generated_at'   => now(),
            'version'        => '1.0',
            'updated_at'     => now(),
        ];

        if ($existing) {
            DB::table('meridian_brand_packages')
                ->where('id', $existing->id)
                ->update($data);
        } else {
            $data['created_at'] = now();
            DB::table('meridian_brand_packages')->insert($data);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function brandSlug(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
    }
}
