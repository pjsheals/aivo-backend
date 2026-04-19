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
}
