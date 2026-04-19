<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianCrawlerGenerator — Module 6
 *
 * Generates crawler instruction files for a brand based on published atoms
 * and M9 Brand Intelligence Packages.
 *
 * Produces:
 *   - robots.txt  — per-agent crawl permissions + Content Signals directive
 *                   + routing to platform-specific Brand Intelligence Package
 *                   JSON files at /.well-known/llm/
 *   - llms.txt    — root-level discovery file listing all package paths and
 *                   routing each crawler to its model-specific file
 *
 * Both files are stored in meridian_crawler_instructions and returned
 * as text for client deployment.
 */
class MeridianCrawlerGenerator
{
    // AI crawler user agents — M9 platforms + ancillary crawlers
    private const CRAWLERS = [
        'chatgpt'    => ['GPTBot', 'ChatGPT-User', 'OAI-SearchBot'],
        'gemini'     => ['Google-Extended', 'Googlebot'],
        'perplexity' => ['PerplexityBot'],
        'claude'     => ['ClaudeBot', 'anthropic-ai'],
        'grok'       => ['Grok', 'xAIbot'],
        'bing'       => ['Bingbot', 'msnbot'],
        'meta'       => ['FacebookBot', 'meta-externalagent'],
        'apple'      => ['Applebot'],
    ];

    // Platforms with dedicated M9 Brand Intelligence Packages
    private const M9_PLATFORMS = ['chatgpt', 'gemini', 'perplexity', 'claude', 'grok'];

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    public function generate(int $brandId, int $agencyId): array
    {
        $brand = DB::table('meridian_brands')->find($brandId);
        if (!$brand) throw new \RuntimeException("Brand {$brandId} not found.");

        // Published atoms (for DOI references and legacy atom section in llms.txt)
        $atoms = DB::table('meridian_atoms')
            ->where('brand_id', $brandId)
            ->where('status', 'published')
            ->get();

        // Publication job results for DOIs/URLs
        $atomIds = $atoms->pluck('id')->toArray();
        $jobs    = [];
        if (!empty($atomIds)) {
            $jobRows = DB::table('meridian_publication_jobs')
                ->whereIn('atom_id', $atomIds)
                ->where('status', 'completed')
                ->get();
            foreach ($jobRows as $job) {
                $jobs[$job->atom_id][$job->destination] = $job;
            }
        }

        // M9 package status — which platforms have generated packages
        $packageRows = DB::table('meridian_brand_packages')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId)
            ->get()
            ->keyBy('platform');

        $robotsTxt = $this->buildRobotsTxt($brand, $atoms, $jobs, $packageRows);
        $llmsTxt   = $this->buildLlmsTxt($brand, $atoms, $jobs, $packageRows);

        // Upsert — one record per brand
        $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $existing = DB::table('meridian_crawler_instructions')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId)
            ->first();

        if ($existing) {
            DB::table('meridian_crawler_instructions')
                ->where('id', $existing->id)
                ->update([
                    'robots_txt'   => $robotsTxt,
                    'llms_txt'     => $llmsTxt,
                    'atom_count'   => count($atoms),
                    'generated_at' => now(),
                    'updated_at'   => now(),
                ]);
            $id = $existing->id;
        } else {
            DB::table('meridian_crawler_instructions')->insert([
                'id'           => $id,
                'brand_id'     => $brandId,
                'agency_id'    => $agencyId,
                'robots_txt'   => $robotsTxt,
                'llms_txt'     => $llmsTxt,
                'atom_count'   => count($atoms),
                'generated_at' => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        return [
            'id'         => $id,
            'brand_id'   => $brandId,
            'brand_name' => $brand->name,
            'robots_txt' => $robotsTxt,
            'llms_txt'   => $llmsTxt,
            'atom_count' => count($atoms),
        ];
    }

    public function get(int $brandId, int $agencyId): ?array
    {
        $record = DB::table('meridian_crawler_instructions')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId)
            ->first();

        if (!$record) return null;

        return [
            'id'           => $record->id,
            'brand_id'     => $record->brand_id,
            'robots_txt'   => $record->robots_txt,
            'llms_txt'     => $record->llms_txt,
            'atom_count'   => $record->atom_count,
            'generated_at' => $record->generated_at,
        ];
    }

    // -------------------------------------------------------------------------
    // robots.txt builder
    // -------------------------------------------------------------------------

    private function buildRobotsTxt(object $brand, $atoms, array $jobs, object $packageRows): string
    {
        $brandSlug = $this->brandSlug($brand->name);
        $lines     = [];

        $lines[] = "# Generated by AIVO Meridian — {$brand->name}";
        $lines[] = "# Generated: " . date('Y-m-d H:i:s') . " UTC";
        $lines[] = "# Brand Intelligence Packages: " . count($packageRows);
        $lines[] = "# Atom count: " . count($atoms);
        $lines[] = "";

        // ── Content Signals directive ─────────────────────────────────────────
        // Cloudflare Content Signals standard (contentsignals.org)
        // Declares per-brand AI usage preferences independently of crawl rules.
        //   ai-train=no    — Do not use this content to train AI models
        //   ai-input=yes   — Content may be used as AI inference / grounding input
        //   search=yes     — Content should appear in AI search results
        //
        // These defaults reflect the standard brand position: willing to be cited
        // in AI answers (ai-input=yes) and AI search (search=yes), but not willing
        // to have content used as training data without consent (ai-train=no).
        // Agencies can advise clients to adjust ai-train based on their preference.
        $lines[] = "# ── Content Signals (contentsignals.org) ──────────────────────────────";
        $lines[] = "# Declares AI usage preferences for this brand's content.";
        $lines[] = "# ai-train=no   → Do not use for model training";
        $lines[] = "# ai-input=yes  → May be used for AI inference and grounding";
        $lines[] = "# search=yes    → Include in AI-powered search results";
        $lines[] = "User-agent: *";
        $lines[] = "Content-Signal: ai-train=no, ai-input=yes, search=yes";
        $lines[] = "";

        // Universal — allow all crawlers access to well-known and llms.txt
        $lines[] = "# ── Universal access ──────────────────────────────────────────────────";
        $lines[] = "User-agent: *";
        $lines[] = "Allow: /.well-known/";
        $lines[] = "Allow: /llms.txt";
        $lines[] = "Disallow: /internal/";
        $lines[] = "";

        // Per-crawler routing to platform-specific Brand Intelligence Packages
        $lines[] = "# ── Platform-specific routing ──────────────────────────────────────────";
        foreach (self::CRAWLERS as $platform => $agents) {
            $lines[] = "# " . ucfirst($platform);
            foreach ($agents as $agent) {
                $lines[] = "User-agent: {$agent}";
            }

            if (in_array($platform, self::M9_PLATFORMS, true)) {
                $packagePath = "/.well-known/llm/{$brandSlug}-{$platform}.json";
                $lines[] = "Allow: {$packagePath}";
                $lines[] = "Allow: /.well-known/brand.context";
                $lines[] = "Allow: /llms.txt";
            } else {
                $lines[] = "Allow: /.well-known/";
                $lines[] = "Allow: /llms.txt";
            }

            $lines[] = "Disallow: /internal/";
            $lines[] = "";
        }

        // Zenodo DOI references (from published atoms)
        $zenodoDois = $this->getZenodoDois($atoms, $jobs);
        if (!empty($zenodoDois)) {
            $lines[] = "# ── Published atom DOIs ────────────────────────────────────────────────";
            foreach ($zenodoDois as $filterType => $doi) {
                $lines[] = "# Filter {$filterType}: https://doi.org/{$doi}";
            }
            $lines[] = "";
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // llms.txt builder
    // -------------------------------------------------------------------------

    private function buildLlmsTxt(object $brand, $atoms, array $jobs, object $packageRows): string
    {
        $brandSlug = $this->brandSlug($brand->name);
        $lines     = [];

        $lines[] = "# {$brand->name} — AI Brand Intelligence";
        $lines[] = "# Generated by AIVO Meridian";
        $lines[] = "# Standard: MAS 1.1 / AIVO Evidentia Filter Taxonomy WP-2026-01";
        $lines[] = "# Generated: " . date('Y-m-d');
        $lines[] = "";
        $lines[] = "## Brand";
        $lines[] = "name: {$brand->name}";
        if (!empty($brand->website)) {
            $lines[] = "url: {$brand->website}";
        }
        if (!empty($brand->category)) {
            $lines[] = "category: {$brand->category}";
        }
        $lines[] = "";

        // Brand context files (M2)
        $lines[] = "## Brand Context";
        $lines[] = "brand-context: /.well-known/brand.context";
        $lines[] = "";

        // Content Signals summary in llms.txt for LLM-readable context
        $lines[] = "## Content Usage Permissions";
        $lines[] = "# Content Signals standard (contentsignals.org)";
        $lines[] = "ai-train: no";
        $lines[] = "ai-input: yes";
        $lines[] = "search: yes";
        $lines[] = "";

        // Brand Intelligence Packages (M9)
        $lines[] = "## Brand Intelligence Packages";
        $lines[] = "# Platform-optimised JSON files for AI crawler consumption.";
        $lines[] = "# Each file contains displacement map, approved atoms, evidence chain,";
        $lines[] = "# conversational Q&A, and platform-specific weighting.";
        $lines[] = "";

        $platformLabels = [
            'chatgpt'    => 'ChatGPT    (GPTBot / OAI-SearchBot)  · Training corpus · 8–16 week cycle',
            'gemini'     => 'Gemini     (Google-Extended)          · Training corpus · 8–16 week cycle',
            'perplexity' => 'Perplexity (PerplexityBot)            · Live retrieval  · Immediate effect',
            'claude'     => 'Claude     (ClaudeBot)                · Training corpus · 8–16 week cycle',
            'grok'       => 'Grok       (xAIbot)                   · Live web        · Real-time',
        ];

        foreach (self::M9_PLATFORMS as $platform) {
            $path      = "/.well-known/llm/{$brandSlug}-{$platform}.json";
            $label     = $platformLabels[$platform];
            $generated = $packageRows->has($platform) && $packageRows->get($platform)->generated_at;
            $atomCount = $packageRows->has($platform) ? (int)$packageRows->get($platform)->atom_count : 0;
            $evCount   = $packageRows->has($platform) ? (int)$packageRows->get($platform)->evidence_count : 0;

            $lines[] = "# {$label}";
            $lines[] = "package-{$platform}: {$path}";
            if ($generated) {
                $lines[] = "package-{$platform}-atoms: {$atomCount}";
                $lines[] = "package-{$platform}-evidence: {$evCount}";
            }
            $lines[] = "";
        }

        // Published atom DOIs
        if (count($atoms) > 0) {
            $zenodoDois = $this->getZenodoDois($atoms, $jobs);
            if (!empty($zenodoDois)) {
                $lines[] = "## Published Evidence DOIs";
                foreach ($zenodoDois as $filterType => $doi) {
                    $lines[] = "evidence-{$filterType}-doi: https://doi.org/{$doi}";
                }
                $lines[] = "";
            }
        }

        // Crawler routing
        $lines[] = "## Crawler Routing";
        $lines[] = "# Each AI crawler is routed to its platform-specific Brand Intelligence Package.";
        $lines[] = "";

        foreach (self::CRAWLERS as $platform => $agents) {
            $agentList = implode(', ', $agents);

            if (in_array($platform, self::M9_PLATFORMS, true)) {
                $packagePath = "/.well-known/llm/{$brandSlug}-{$platform}.json";
                $lines[] = "{$platform}-agents: {$agentList}";
                $lines[] = "{$platform}-package: {$packagePath}";
                $lines[] = "{$platform}-context: /.well-known/brand.context";
            } else {
                $lines[] = "{$platform}-agents: {$agentList}";
                $lines[] = "{$platform}-package: /.well-known/llm/";
                $lines[] = "{$platform}-context: /.well-known/brand.context";
            }
            $lines[] = "";
        }

        $lines[] = "## License";
        $lines[] = "license: CC-BY-4.0";
        $lines[] = "publisher: AIVO Research Intelligence Platform";
        $lines[] = "contact: edge@aivoedge.net";

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getZenodoDois($atoms, array $jobs): array
    {
        $dois = [];
        foreach ($atoms as $atom) {
            if (isset($jobs[$atom->id]['zenodo'])) {
                $doi = $jobs[$atom->id]['zenodo']->result_doi;
                if ($doi) {
                    $dois[$atom->filter_type] = $doi;
                }
            }
        }
        return $dois;
    }

    private function brandSlug(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
    }

    // -------------------------------------------------------------------------
    // AI-optimised llms.txt generation
    // -------------------------------------------------------------------------

    /**
     * Generate an AI-optimised llms.txt using displacement intelligence.
     *
     * Unlike the basic buildLlmsTxt() which produces a templated file from
     * package paths, this method uses Claude to write semantically rich
     * descriptions for each entry — descriptions that directly address the
     * specific displacement criteria identified by M1 classification.
     *
     * The output is written for LLMs to read, not humans. Each entry answers
     * the exact question the AI model applies at the displacement turn.
     *
     * @param int $brandId
     * @param int $agencyId
     * @param int|null $auditId  — if supplied, uses classifications from this audit
     */
    public function generateOptimised(int $brandId, int $agencyId, ?int $auditId = null): array
    {
        $brand = DB::table('meridian_brands')->find($brandId);
        if (!$brand) throw new \RuntimeException("Brand {$brandId} not found.");

        $claudeKey = getenv('ANTHROPIC_API_KEY') ?: '';
        if (!$claudeKey) throw new \RuntimeException("ANTHROPIC_API_KEY not set.");

        // ── Load displacement intelligence ────────────────────────────────────

        // M1 classifications — displacement criteria per platform
        $classificationsQuery = DB::table('meridian_filter_classifications')
            ->where('brand_id', $brandId)
            ->orderByDesc('confidence_score');
        if ($auditId) {
            $classificationsQuery->where('audit_id', $auditId);
        }
        $classifications = $classificationsQuery->get();

        // Evidence submissions (verified first, then all)
        $evidenceQuery = DB::table('meridian_evidence_submissions')
            ->where('brand_id', $brandId)
            ->orderByDesc('authority_score')
            ->orderByDesc('created_at');
        if ($auditId) {
            $evidenceQuery->where('audit_id', $auditId);
        }
        $evidenceItems = $evidenceQuery->get();

        // Approved atoms
        $atoms = DB::table('meridian_atoms')
            ->where('brand_id', $brandId)
            ->whereIn('approval_status', ['approved', 'published'])
            ->orderByDesc('created_at')
            ->get();

        // M9 packages
        $packageRows = DB::table('meridian_brand_packages')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId)
            ->get()->keyBy('platform');

        // RCS and audit result
        $auditResult = null;
        if ($auditId) {
            $auditResult = DB::table('meridian_brand_audit_results')
                ->where('audit_id', $auditId)->first();
        } else {
            $auditResult = DB::table('meridian_brand_audit_results')
                ->where('brand_id', $brandId)
                ->orderByDesc('created_at')->first();
        }

        // ── Build intelligence summary for Claude ─────────────────────────────

        $displacementSummary = $this->buildDisplacementSummary($classifications);
        $evidenceSummary     = $this->buildEvidenceSummary($evidenceItems);
        $atomSummary         = $this->buildAtomSummary($atoms);
        $rcs                 = $auditResult ? (float)($auditResult->rcs_total ?? 0) : 0;

        // ── Call Claude to generate optimised llms.txt ────────────────────────

        $prompt = $this->buildOptimisedLlmsPrompt(
            $brand, $packageRows, $displacementSummary,
            $evidenceSummary, $atomSummary, $rcs
        );

        $optimisedLlmsTxt = $this->callClaude($claudeKey, $prompt, $brand->name);

        // Fall back to basic llms.txt if Claude fails
        if (!$optimisedLlmsTxt) {
            $atoms_all = DB::table('meridian_atoms')->where('brand_id', $brandId)->where('status', 'published')->get();
            $jobs = [];
            $optimisedLlmsTxt = $this->buildLlmsTxt($brand, $atoms_all, $jobs, $packageRows);
        }

        // Regenerate robots.txt (unchanged) and store updated crawler instructions
        $atoms_published = DB::table('meridian_atoms')->where('brand_id', $brandId)->where('status', 'published')->get();
        $atomIds = $atoms_published->pluck('id')->toArray();
        $jobs = [];
        if (!empty($atomIds)) {
            $jobRows = DB::table('meridian_publication_jobs')
                ->whereIn('atom_id', $atomIds)->where('status', 'completed')->get();
            foreach ($jobRows as $job) {
                $jobs[$job->atom_id][$job->destination] = $job;
            }
        }
        $robotsTxt = $this->buildRobotsTxt($brand, $atoms_published, $jobs, $packageRows);

        // Upsert
        $existing = DB::table('meridian_crawler_instructions')
            ->where('brand_id', $brandId)->where('agency_id', $agencyId)->first();

        if ($existing) {
            DB::table('meridian_crawler_instructions')
                ->where('id', $existing->id)
                ->update([
                    'robots_txt'   => $robotsTxt,
                    'llms_txt'     => $optimisedLlmsTxt,
                    'atom_count'   => count($atoms_published),
                    'generated_at' => now(),
                    'updated_at'   => now(),
                ]);
            $id = $existing->id;
        } else {
            $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
                mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,
                mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff)
            );
            DB::table('meridian_crawler_instructions')->insert([
                'id'           => $id,
                'brand_id'     => $brandId,
                'agency_id'    => $agencyId,
                'robots_txt'   => $robotsTxt,
                'llms_txt'     => $optimisedLlmsTxt,
                'atom_count'   => count($atoms_published),
                'generated_at' => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        return [
            'id'          => $id,
            'brand_id'    => $brandId,
            'brand_name'  => $brand->name,
            'robots_txt'  => $robotsTxt,
            'llms_txt'    => $optimisedLlmsTxt,
            'atom_count'  => count($atoms_published),
            'optimised'   => true,
        ];
    }

    // ── Intelligence summarisers ──────────────────────────────────────────────

    private function buildDisplacementSummary($classifications): array
    {
        $summary = [];
        foreach ($classifications as $c) {
            $platform = $c->platform;
            if (!isset($summary[$platform])) {
                $summary[$platform] = [
                    'platform'               => $platform,
                    'primary_filter'         => $c->primary_filter,
                    'displacement_criteria'  => $c->displacement_criteria,
                    'displacement_mechanism' => $c->displacement_mechanism,
                    'dit_turn'               => $c->dit_turn,
                    't4_winner'              => $c->t4_winner,
                    'evidence_gaps'          => json_decode($c->evidence_gaps ?? '[]', true),
                    'confidence'             => (int)$c->confidence_score,
                ];
            }
        }
        return array_values($summary);
    }

    private function buildEvidenceSummary($evidenceItems): array
    {
        $summary = [];
        foreach ($evidenceItems as $e) {
            $summary[] = [
                'filter'      => $e->filter_type,
                'source_type' => $e->source_type,
                'source_url'  => $e->source_url,
                'source_title'=> $e->source_title,
                'free_text'   => $e->free_text,
                'authority'   => (int)$e->authority_score,
                'verified'    => $e->verification_status === 'verified',
            ];
        }
        return $summary;
    }

    private function buildAtomSummary($atoms): array
    {
        $summary = [];
        foreach ($atoms as $a) {
            $summary[] = [
                'filter_type' => $a->filter_type,
                'platform'    => $a->model_variant ?? 'universal',
                'content'     => mb_substr($a->atom_content ?? '', 0, 300),
                'status'      => $a->approval_status,
            ];
        }
        return $summary;
    }

    // ── Claude prompt ─────────────────────────────────────────────────────────

    private function buildOptimisedLlmsPrompt(
        object $brand,
        object $packageRows,
        array  $displacementSummary,
        array  $evidenceSummary,
        array  $atomSummary,
        float  $rcs
    ): string {
        $brandSlug    = $this->brandSlug($brand->name);
        $brandName    = $brand->name;
        $category     = $brand->category ?? 'unknown';
        $website      = $brand->website  ?? '';
        $rcsFormatted = number_format($rcs, 0);

        $platformLabels = [
            'chatgpt'    => 'ChatGPT (GPTBot/OAI-SearchBot) — probabilistic training data, 8–16 week propagation',
            'gemini'     => 'Gemini (Google-Extended) — Educational Drift Arc, clinical evidence hierarchy',
            'perplexity' => 'Perplexity (PerplexityBot) — live RAG retrieval, immediate effect',
            'claude'     => 'Claude (ClaudeBot) — training corpus, citation authority',
            'grok'       => 'Grok (xAIbot) — live web, performance-first determinism',
        ];

        $displacementJson = json_encode($displacementSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $evidenceJson     = json_encode(array_slice($evidenceSummary, 0, 10), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $atomJson         = json_encode(array_slice($atomSummary, 0, 5), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $packagePaths = '';
        foreach (['chatgpt','gemini','perplexity','claude','grok'] as $p) {
            $path = "/.well-known/llm/{$brandSlug}-{$p}.json";
            $packagePaths .= "  {$p}: {$path}
";
        }

        return <<<PROMPT
You are an expert AI brand intelligence architect. Generate a semantically optimised llms.txt file for the brand below.

llms.txt is read by AI models during inference to understand a brand's positioning, evidence, and commercial intent. Unlike a sitemap which lists pages, llms.txt must communicate what the brand IS, what questions it answers, and where to find authoritative evidence — all in a format that fits within an LLM context window and directly addresses the specific questions AI models apply when deciding whether to recommend this brand.

CRITICAL PRINCIPLES:
1. Write descriptions for LLMs to read, not humans. Each description should answer the question an LLM would ask.
2. Descriptions must directly address the displacement criteria — the exact question the AI applied when routing away from this brand.
3. Use the evidence items to make claims specific and verifiable — name the sources.
4. Every entry must have a semantic title that tells the LLM what it will find before it fetches the URL.
5. Hierarchical structure: brand overview → what the brand does → decision-stage evidence per platform → competitive context.
6. Fit within a single context window — quality over quantity. Aim for 60–90 lines total.
7. Do not reproduce this prompt. Output only the llms.txt content.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
BRAND
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Name: {$brandName}
Category: {$category}
Website: {$website}
Reasoning Chain Score: {$rcsFormatted}/100
Brand slug: {$brandSlug}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
DISPLACEMENT INTELLIGENCE (M1 Classification)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$displacementJson}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
EVIDENCE SUBMITTED
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$evidenceJson}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
APPROVED ATOMS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$atomJson}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
BRAND INTELLIGENCE PACKAGE PATHS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$packagePaths}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
PLATFORM FINGERPRINTS (apply when writing platform-specific entries)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$platformLabels['chatgpt']}
{$platformLabels['gemini']}
{$platformLabels['perplexity']}
{$platformLabels['claude']}
{$platformLabels['grok']}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
REQUIRED STRUCTURE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

# {brand name} — AI Brand Intelligence
# Optimised by AIVO Meridian Displacement Intelligence Engine
# Generated: {today}
# Standard: llms.txt / AIVO Meridian MAS 1.1

> {One precise sentence: what this brand is and what specific capability it provides. 
  Write it so an LLM can immediately categorise the brand correctly without ambiguity.
  This is the most important line — it prevents category misclassification.}

## What {Brand} Does
- [{Capability title}]({package path}): {One sentence answering the most common LLM question about this brand's core capability. Use specific, verifiable language.}
- [{Second capability}]({package path}): {One sentence. Be specific.}

## Decision-Stage Evidence
> These entries directly address the displacement criteria identified across AI platforms.
> Each entry answers the specific question AI models apply at the purchase decision turn.

- [{Platform}: {displacement criteria rephrased as a capability title}]({platform package path}): {2 sentences. First: what question this addresses and why it matters. Second: what specific evidence answers it — name the source.}
[Repeat for each platform that has displacement data]

## Content Usage Permissions
ai-train: no
ai-input: yes
search: yes

## Crawler Routing
[List each platform agent → package path mapping]

## License
license: CC-BY-4.0
publisher: AIVO Research Intelligence Platform
contact: edge@aivoedge.net

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
RULES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- The > brand description line is the most critical. Make it unambiguous. If the brand has T0 entity recognition failure, this line must establish the category definitively.
- Each Decision-Stage Evidence entry must name the displacement criteria from the M1 data — not a generic description.
- If no evidence has been submitted yet, write the entry as what the brand NEEDS to establish — frame it as a gap to address, not as evidence that exists.
- If atoms exist, their content can inform the capability descriptions.
- Do not invent evidence that wasn't in the data provided.
- Platform fingerprints inform the description style: ChatGPT entries emphasise training-data permanence; Perplexity entries emphasise live retrievability and dated content.
- Output the llms.txt content only. No preamble, no explanation, no markdown fences.
PROMPT;
    }

    private function callClaude(string $apiKey, string $prompt, string $brandName): ?string
    {
        $payload = json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 2000,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) {
            error_log("[MeridianCrawlerGenerator] Claude API error HTTP={$httpCode} err={$curlErr}");
            return null;
        }

        $decoded = json_decode($response, true);
        $text    = $decoded['content'][0]['text'] ?? null;

        if (!$text) {
            error_log("[MeridianCrawlerGenerator] Empty Claude response for {$brandName}");
            return null;
        }

        // Strip any markdown fences Claude might add despite instructions
        $text = preg_replace('/^```[a-z]*\s*/m', '', $text);
        $text = preg_replace('/^```\s*$/m', '', $text);
        return trim($text);
    }

}
