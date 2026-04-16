<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianPublicationPipeline — Module 5
 *
 * Publishes validated atoms to authoritative external nodes.
 * Core (all brands):   Zenodo, GitHub, HuggingFace, Internet Archive
 * Sector-specific:     OSF (Beauty/Health/Pharma), figshare (optional),
 *                      dev.to (Tech/SaaS)
 * Manual packages:     Wikidata, Academia.edu, ORCID
 *
 * Each destination runs as an independent job with retry logic.
 * All jobs are tracked in meridian_publication_jobs.
 */
class MeridianPublicationPipeline
{
    private const CORE_DESTINATIONS = ['zenodo', 'github', 'huggingface', 'internet_archive'];

    private const SECTOR_DESTINATIONS = [
        'beauty'    => ['osf'],
        'skincare'  => ['osf'],
        'health'    => ['osf'],
        'pharma'    => ['osf', 'figshare'],
        'clinical'  => ['osf', 'figshare'],
        'tech'      => ['devto', 'figshare'],
        'saas'      => ['devto'],
        'software'  => ['devto'],
    ];

    private const MANUAL_DESTINATIONS = ['wikidata', 'academia', 'orcid'];

    private const RETRY_DELAYS = [0, 60, 300]; // seconds between attempts

    // -------------------------------------------------------------------------
    // Public entry point — queue publication jobs for an atom
    // -------------------------------------------------------------------------

    public function queueAtom(string $atomId, array $options = []): array
    {
        $atom = DB::table('meridian_atoms')->where('id', $atomId)->first();
        if (!$atom) throw new \RuntimeException("Atom {$atomId} not found.");
        if ($atom->status !== 'validated') throw new \RuntimeException("Atom must be validated before publishing. Current status: {$atom->status}");

        $brand = DB::table('meridian_brands')->find($atom->brand_id);
        if (!$brand) throw new \RuntimeException("Brand not found.");

        // Determine destinations
        $destinations = $this->resolveDestinations($brand, $options);

        $jobIds = [];
        foreach ($destinations['automated'] as $destination) {
            $jobId = $this->createJob($atomId, (int)$atom->brand_id, (int)$atom->audit_id, (int)$atom->agency_id, $destination);
            $jobIds[] = ['destination' => $destination, 'job_id' => $jobId];
        }

        // Generate manual submission packages
        $manualPackages = [];
        foreach ($destinations['manual'] as $destination) {
            $package = $this->generateManualPackage($atom, $brand, $destination);
            $manualPackages[] = $package;
        }

        return [
            'atom_id'         => $atomId,
            'jobs_queued'     => $jobIds,
            'manual_packages' => $manualPackages,
            'total_automated' => count($jobIds),
            'total_manual'    => count($manualPackages),
        ];
    }

    // -------------------------------------------------------------------------
    // Process a single job (called by worker or synchronously for testing)
    // -------------------------------------------------------------------------

    public function processJob(string $jobId): array
    {
        $job = DB::table('meridian_publication_jobs')->where('id', $jobId)->first();
        if (!$job) throw new \RuntimeException("Job {$jobId} not found.");

        if ($job->status === 'completed') {
            return ['status' => 'completed', 'result_url' => $job->result_url];
        }

        if ($job->attempt_count >= $job->max_attempts) {
            DB::table('meridian_publication_jobs')->where('id', $jobId)->update([
                'status'     => 'failed',
                'updated_at' => now(),
            ]);
            return ['status' => 'failed', 'error' => 'Max attempts reached.'];
        }

        // Mark as running
        DB::table('meridian_publication_jobs')->where('id', $jobId)->update([
            'status'        => 'running',
            'started_at'    => now(),
            'attempt_count' => $job->attempt_count + 1,
            'updated_at'    => now(),
        ]);

        $atom  = DB::table('meridian_atoms')->where('id', $job->atom_id)->first();
        $brand = DB::table('meridian_brands')->find($job->brand_id);

        try {
            $result = match($job->destination) {
                'zenodo'           => $this->publishToZenodo($atom, $brand),
                'github'           => $this->publishToGitHub($atom, $brand),
                'huggingface'      => $this->publishToHuggingFace($atom, $brand),
                'internet_archive' => $this->publishToInternetArchive($atom, $brand),
                'osf'              => $this->publishToOsf($atom, $brand),
                'figshare'         => $this->publishToFigshare($atom, $brand),
                'devto'            => $this->publishToDevTo($atom, $brand),
                default            => throw new \RuntimeException("Unknown destination: {$job->destination}"),
            };

            // Success — update job and atom
            DB::table('meridian_publication_jobs')->where('id', $jobId)->update([
                'status'       => 'completed',
                'result_url'   => $result['url']      ?? null,
                'result_doi'   => $result['doi']      ?? null,
                'result_id'    => $result['id']       ?? null,
                'response'     => json_encode($result),
                'completed_at' => now(),
                'updated_at'   => now(),
            ]);

            // Update atom with DOI/URL
            $atomUpdate = ['updated_at' => now()];
            if (!empty($result['doi']))  $atomUpdate['zenodo_doi']  = $result['doi'];
            if (!empty($result['url'])) {
                if ($job->destination === 'github')      $atomUpdate['github_url'] = $result['url'];
                if ($job->destination === 'huggingface') $atomUpdate['hf_url']     = $result['url'];
            }
            if (count($atomUpdate) > 1) {
                DB::table('meridian_atoms')->where('id', $job->atom_id)->update($atomUpdate);
            }

            // Mark atom as published if all core jobs completed
            $this->checkAndMarkPublished($job->atom_id);

            return ['status' => 'completed', 'result' => $result];

        } catch (\Throwable $e) {
            $attemptCount = $job->attempt_count + 1;
            $nextRetry    = null;
            $newStatus    = 'failed';

            if ($attemptCount < $job->max_attempts) {
                $delaySeconds = self::RETRY_DELAYS[$attemptCount] ?? 300;
                $nextRetry    = date('Y-m-d H:i:s', strtotime("+{$delaySeconds} seconds"));
                $newStatus    = 'retrying';
            }

            DB::table('meridian_publication_jobs')->where('id', $jobId)->update([
                'status'        => $newStatus,
                'error_message' => substr($e->getMessage(), 0, 500),
                'next_retry_at' => $nextRetry,
                'updated_at'    => now(),
            ]);

            log_error("[M5] Job {$jobId} failed on {$job->destination}", ['error' => $e->getMessage()]);

            return ['status' => $newStatus, 'error' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Zenodo publication
    // -------------------------------------------------------------------------

    private function publishToZenodo(object $atom, object $brand): array
    {
        $token   = env('ZENODO_TOKEN');
        $baseUrl = env('ZENODO_SANDBOX') ? 'https://sandbox.zenodo.org/api' : 'https://zenodo.org/api';

        if (!$token) throw new \RuntimeException('ZENODO_TOKEN not configured.');

        $rawAtom   = json_decode($atom->raw_atom ?? '{}', true);
        $brandSlug = $this->brandSlug($brand->name);

        // 1. Create deposition
        $deposition = $this->zenodoRequest('POST', "{$baseUrl}/deposit/depositions", $token, [
            'metadata' => [
                'title'          => "AIVO Meridian Atom — {$brand->name} — Filter {$atom->filter_type} ({$atom->model_variant})",
                'upload_type'    => 'dataset',
                'description'    => $rawAtom['claim'] ?? "Brand context atom for {$brand->name}",
                'creators'       => [['name' => 'AIVO Research Intelligence Platform', 'affiliation' => 'AIVO Edge']],
                'keywords'       => ['brand-context', 'ai-optimisation', 'mas-1.1', $brandSlug, $atom->filter_type],
                'license'        => 'cc-by-4.0',
                'communities'    => [['identifier' => 'aivo-brands']],
                'notes'          => "Generated by AIVO Meridian. Filter: {$atom->filter_type}. Model variant: {$atom->model_variant}. Standard: AIVO Evidentia Filter Taxonomy WP-2026-01.",
            ],
        ]);

        if (empty($deposition['id'])) {
            throw new \RuntimeException('Zenodo deposition creation failed: ' . json_encode($deposition));
        }

        $depositionId = $deposition['id'];
        $bucketUrl    = $deposition['links']['bucket'] ?? null;

        // 2. Upload atom JSON file
        $filename    = "atom-{$brandSlug}-{$atom->filter_type}-{$atom->model_variant}.json";
        $fileContent = json_encode($rawAtom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($bucketUrl) {
            $this->zenodoUploadFile("{$bucketUrl}/{$filename}", $token, $fileContent);
        }

        // 3. Publish deposition
        $published = $this->zenodoRequest('POST', "{$baseUrl}/deposit/depositions/{$depositionId}/actions/publish", $token);

        $doi = $published['doi'] ?? $published['metadata']['doi'] ?? null;
        $url = $published['links']['record_html'] ?? "https://zenodo.org/record/{$depositionId}";

        return ['doi' => $doi, 'url' => $url, 'id' => (string)$depositionId];
    }

    private function zenodoRequest(string $method, string $url, string $token, array $body = []): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 30,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("Zenodo HTTP {$httpCode}: {$response}");
        }
        return json_decode($response, true) ?: [];
    }

    private function zenodoUploadFile(string $url, string $token, string $content): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $content,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/octet-stream',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 400) {
            throw new \RuntimeException("Zenodo file upload HTTP {$httpCode}: {$response}");
        }
    }

    // -------------------------------------------------------------------------
    // GitHub publication
    // -------------------------------------------------------------------------

    private function publishToGitHub(object $atom, object $brand): array
    {
        $token  = env('GITHUB_TOKEN');
        $org    = env('GITHUB_ORG', 'aivo-brands');

        if (!$token) throw new \RuntimeException('GITHUB_TOKEN not configured.');

        $brandSlug = $this->brandSlug($brand->name);
        $repoName  = "{$brandSlug}-atoms";
        $filename  = "atoms/{$atom->filter_type}-{$atom->model_variant}.json";
        $rawAtom   = json_decode($atom->raw_atom ?? '{}', true);
        $content   = base64_encode(json_encode($rawAtom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Ensure repo exists
        $this->githubEnsureRepo($org, $repoName, $token, $brand->name);

        // Get existing file SHA if updating
        $sha = $this->githubGetFileSha($org, $repoName, $filename, $token);

        $body = [
            'message' => "Add atom: {$atom->filter_type} ({$atom->model_variant})",
            'content' => $content,
        ];
        if ($sha) $body['sha'] = $sha;

        $result = $this->githubRequest(
            'PUT',
            "https://api.github.com/repos/{$org}/{$repoName}/contents/{$filename}",
            $token,
            $body
        );

        $url = "https://github.com/{$org}/{$repoName}/blob/main/{$filename}";
        return ['url' => $url, 'id' => "{$org}/{$repoName}/{$filename}"];
    }

    private function githubEnsureRepo(string $org, string $repo, string $token, string $brandName): void
    {
        // Check if repo exists
        $ch = curl_init("https://api.github.com/repos/{$org}/{$repo}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", "User-Agent: AIVO-Meridian"],
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) return; // Already exists

        // Detect personal account vs org — personal accounts use /user/repos
        $userCh = curl_init('https://api.github.com/user');
        curl_setopt_array($userCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", "User-Agent: AIVO-Meridian"],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $userResponse = curl_exec($userCh);
        curl_close($userCh);

        $userData  = json_decode($userResponse, true);
        $login     = $userData['login'] ?? null;
        $createUrl = ($login && strtolower($login) === strtolower($org))
            ? 'https://api.github.com/user/repos'
            : "https://api.github.com/orgs/{$org}/repos";

        $this->githubRequest('POST', $createUrl, $token, [
            'name'        => $repo,
            'description' => "AIVO Meridian atoms for {$brandName}",
            'private'     => false,
            'auto_init'   => true,
        ]);
    }

    private function githubGetFileSha(string $org, string $repo, string $path, string $token): ?string
    {
        $ch = curl_init("https://api.github.com/repos/{$org}/{$repo}/contents/{$path}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", "User-Agent: AIVO-Meridian"],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) return null;
        $data = json_decode($response, true);
        return $data['sha'] ?? null;
    }

    private function githubRequest(string $method, string $url, string $token, array $body = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
                'User-Agent: AIVO-Meridian',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("GitHub HTTP {$httpCode}: {$response}");
        }
        return json_decode($response, true) ?: [];
    }

    // -------------------------------------------------------------------------
    // HuggingFace publication
    // -------------------------------------------------------------------------

    private function publishToHuggingFace(object $atom, object $brand): array
    {
        $token     = env('HUGGINGFACE_TOKEN');
        $namespace = env('HUGGINGFACE_NAMESPACE', 'pjsheals');

        if (!$token) throw new \RuntimeException('HUGGINGFACE_TOKEN not configured.');

        $brandSlug = $this->brandSlug($brand->name);
        $repoId    = "{$namespace}/{$brandSlug}-atoms";
        $filename  = "atoms/{$atom->filter_type}-{$atom->model_variant}.json";
        $rawAtom   = json_decode($atom->raw_atom ?? '{}', true);
        $content   = json_encode($rawAtom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Ensure dataset repo exists
        $this->hfEnsureRepo($repoId, $token, $brand->name);

        // Upload file via PUT to direct resolve endpoint
        $uploadUrl = "https://huggingface.co/datasets/{$repoId}/resolve/main/{$filename}";
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $content,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/octet-stream',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("HuggingFace upload HTTP {$httpCode}: {$response}");
        }

        $url = "https://huggingface.co/datasets/{$repoId}/blob/main/{$filename}";
        return ['url' => $url, 'id' => $repoId];
    }

    private function hfEnsureRepo(string $repoId, string $token, string $brandName): void
    {
        // Check if repo exists
        $ch = curl_init("https://huggingface.co/api/datasets/{$repoId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) return;

        // Extract just the repo name from "namespace/repo-name"
        $repoName = strpos($repoId, '/') !== false
            ? substr($repoId, strpos($repoId, '/') + 1)
            : $repoId;

        // Create repo via current HF API
        $ch = curl_init('https://huggingface.co/api/repos/create');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'type'    => 'dataset',
                'name'    => $repoName,
                'private' => false,
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // -------------------------------------------------------------------------
    // Internet Archive
    // -------------------------------------------------------------------------

    private function publishToInternetArchive(object $atom, object $brand): array
    {
        $accessKey = env('INTERNET_ARCHIVE_ACCESS_KEY');
        $secretKey = env('INTERNET_ARCHIVE_SECRET_KEY');

        if (!$accessKey || !$secretKey) throw new \RuntimeException('Internet Archive credentials not configured.');

        $brandSlug  = $this->brandSlug($brand->name);
        $identifier = "aivo-meridian-{$brandSlug}-{$atom->filter_type}-{$atom->model_variant}";
        $rawAtom    = json_decode($atom->raw_atom ?? '{}', true);
        $content    = json_encode($rawAtom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $filename   = "atom-{$atom->filter_type}-{$atom->model_variant}.json";

        $uploadUrl = "https://s3.us.archive.org/{$identifier}/{$filename}";

        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $content,
            CURLOPT_HTTPHEADER     => [
                "Authorization: LOW {$accessKey}:{$secretKey}",
                'Content-Type: application/json',
                "x-archive-meta-title: AIVO Meridian Atom — {$brand->name} — {$atom->filter_type}",
                "x-archive-meta-subject: brand-context;ai-optimisation;mas-1.1",
                "x-archive-meta-mediatype: data",
                'x-archive-auto-make-bucket: 1',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("Internet Archive HTTP {$httpCode}: {$response}");
        }

        $url = "https://archive.org/details/{$identifier}";
        return ['url' => $url, 'id' => $identifier];
    }

    // -------------------------------------------------------------------------
    // OSF (Open Science Framework)
    // -------------------------------------------------------------------------

    private function publishToOsf(object $atom, object $brand): array
    {
        $token = env('OSF_TOKEN');
        if (!$token) throw new \RuntimeException('OSF_TOKEN not configured.');

        $rawAtom  = json_decode($atom->raw_atom ?? '{}', true);
        $content  = json_encode($rawAtom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Create project node
        $project = $this->osfRequest('POST', 'https://api.osf.io/v2/nodes/', $token, [
            'data' => [
                'type'       => 'nodes',
                'attributes' => [
                    'title'    => "AIVO Meridian — {$brand->name} — {$atom->filter_type}",
                    'category' => 'data',
                    'public'   => true,
                    'description' => $rawAtom['claim'] ?? '',
                ],
            ],
        ]);

        $nodeId = $project['data']['id'] ?? null;
        if (!$nodeId) throw new \RuntimeException('OSF project creation failed.');

        // Upload file to OSF storage
        $filename  = "atom-{$atom->filter_type}-{$atom->model_variant}.json";
        $uploadUrl = "https://files.osf.io/v1/resources/{$nodeId}/providers/osfstorage/?name={$filename}";

        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $content,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_exec($ch);
        curl_close($ch);

        $url = "https://osf.io/{$nodeId}/";
        return ['url' => $url, 'id' => $nodeId];
    }

    private function osfRequest(string $method, string $url, string $token, array $body = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/vnd.api+json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("OSF HTTP {$httpCode}: {$response}");
        }
        return json_decode($response, true) ?: [];
    }

    // -------------------------------------------------------------------------
    // figshare
    // -------------------------------------------------------------------------

    private function publishToFigshare(object $atom, object $brand): array
    {
        $token = env('FIGSHARE_TOKEN');
        if (!$token) throw new \RuntimeException('FIGSHARE_TOKEN not configured.');

        $rawAtom = json_decode($atom->raw_atom ?? '{}', true);

        // Create article
        $article = $this->figshareRequest('POST', 'https://api.figshare.com/v2/account/articles', $token, [
            'title'       => "AIVO Meridian Atom — {$brand->name} — {$atom->filter_type} ({$atom->model_variant})",
            'description' => $rawAtom['claim'] ?? '',
            'tags'        => ['brand-context', 'ai-optimisation', 'mas-1.1', $atom->filter_type],
            'categories'  => [77], // Computer Science
            'license'     => 1,    // CC BY
            'defined_type'=> 'dataset',
        ]);

        $articleId = $article['entity_id'] ?? null;
        if (!$articleId) throw new \RuntimeException('figshare article creation failed.');

        // Upload file
        $filename = "atom-{$atom->filter_type}-{$atom->model_variant}.json";
        $content  = json_encode($rawAtom, JSON_PRETTY_PRINT);

        // Initiate upload
        $upload = $this->figshareRequest('POST', "https://api.figshare.com/v2/account/articles/{$articleId}/files", $token, [
            'name' => $filename,
            'size' => strlen($content),
            'md5'  => md5($content),
        ]);

        // Publish
        $this->figshareRequest('POST', "https://api.figshare.com/v2/account/articles/{$articleId}/publish", $token);

        $url = "https://figshare.com/articles/dataset/{$articleId}";
        return ['url' => $url, 'id' => (string)$articleId];
    }

    private function figshareRequest(string $method, string $url, string $token, array $body = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: token {$token}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("figshare HTTP {$httpCode}: {$response}");
        }
        return json_decode($response, true) ?: [];
    }

    // -------------------------------------------------------------------------
    // dev.to (Tech/SaaS sector)
    // -------------------------------------------------------------------------

    private function publishToDevTo(object $atom, object $brand): array
    {
        $token = env('DEVTO_API_KEY');
        if (!$token) throw new \RuntimeException('DEVTO_API_KEY not configured.');

        $rawAtom = json_decode($atom->raw_atom ?? '{}', true);
        $query   = $rawAtom['conversational']['query']  ?? '';
        $answer  = $rawAtom['conversational']['answer'] ?? '';
        $claim   = $rawAtom['claim'] ?? '';

        $body = "## {$brand->name} — AI Decision Stage Context\n\n";
        $body .= "**Claim:** {$claim}\n\n";
        $body .= "**AI Query at {$atom->reasoning_stage}:** {$query}\n\n";
        $body .= "**Evidence-backed answer:** {$answer}\n\n";
        $body .= "*Published by AIVO Research Intelligence Platform. License: CC-BY-4.0.*";

        $article = $this->devtoRequest('POST', 'https://dev.to/api/articles', $token, [
            'article' => [
                'title'     => "{$brand->name}: AI decision-stage evidence — {$atom->filter_type}",
                'body_markdown' => $body,
                'published' => true,
                'tags'      => ['ai', 'brandcontext', strtolower($brand->category ?? 'tech'), 'mas'],
            ],
        ]);

        $url = $article['url'] ?? null;
        return ['url' => $url, 'id' => (string)($article['id'] ?? '')];
    }

    private function devtoRequest(string $method, string $url, string $token, array $body = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "api-key: {$token}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("dev.to HTTP {$httpCode}: {$response}");
        }
        return json_decode($response, true) ?: [];
    }

    // -------------------------------------------------------------------------
    // Manual submission package generator
    // -------------------------------------------------------------------------

    private function generateManualPackage(object $atom, object $brand, string $destination): array
    {
        $rawAtom   = json_decode($atom->raw_atom ?? '{}', true);
        $brandSlug = $this->brandSlug($brand->name);

        $brief = match($destination) {
            'wikidata' => $this->wikidataBrief($atom, $brand, $rawAtom),
            'academia' => $this->academiaBrief($atom, $brand, $rawAtom),
            'orcid'    => $this->orcidBrief($atom, $brand, $rawAtom),
            default    => 'Manual submission required.',
        };

        $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        DB::table('meridian_manual_submissions')->insert([
            'id'             => $id,
            'atom_id'        => $atom->id,
            'brand_id'       => $atom->brand_id,
            'agency_id'      => $atom->agency_id,
            'destination'    => $destination,
            'brief'          => $brief,
            'structured_data'=> json_encode($rawAtom),
            'status'         => 'pending',
            'created_at'     => now(),
        ]);

        return ['destination' => $destination, 'package_id' => $id, 'brief' => $brief];
    }

    private function wikidataBrief(object $atom, object $brand, array $rawAtom): string
    {
        $claims   = $rawAtom['claim']   ?? '';
        $category = $brand->category    ?? 'product';
        $website  = $brand->website     ?? '';

        return "WIKIDATA SUBMISSION BRIEF — {$brand->name}\n\n"
            . "Check if {$brand->name} has an existing Wikidata entry at https://www.wikidata.org/wiki/Special:Search?search={$brand->name}\n\n"
            . "If entry exists — add or update:\n"
            . "- P31 (instance of): Q167270 (brand)\n"
            . "- P495 (country of origin): [country QID]\n"
            . "- P856 (official website): {$website}\n"
            . "- P452 (industry): [{$category} QID]\n\n"
            . "Key claim to add as description:\n{$claims}\n\n"
            . "If no entry exists — the brand must meet Wikidata notability criteria (significant coverage in independent reliable sources) before creating. Do not create without verifying notability.";
    }

    private function academiaBrief(object $atom, object $brand, array $rawAtom): string
    {
        return "ACADEMIA.EDU SUBMISSION BRIEF — {$brand->name}\n\n"
            . "Upload the atom JSON as a research paper with the following metadata:\n"
            . "Title: AI Decision-Stage Evidence Architecture for {$brand->name} — Filter {$atom->filter_type}\n"
            . "Abstract: " . ($rawAtom['claim'] ?? '') . "\n"
            . "Keywords: brand-context, AI optimisation, MAS 1.1, {$atom->filter_type}, " . ($brand->category ?? '') . "\n\n"
            . "File to upload: atom-{$atom->filter_type}-{$atom->model_variant}.json\n"
            . "License: CC-BY-4.0\n"
            . "Publisher: AIVO Research Intelligence Platform";
    }

    private function orcidBrief(object $atom, object $brand, array $rawAtom): string
    {
        return "ORCID SUBMISSION BRIEF — {$brand->name}\n\n"
            . "If the agency has an ORCID account, add a work entry:\n"
            . "Work type: Data set\n"
            . "Title: AIVO Meridian Atom — {$brand->name} — {$atom->filter_type}\n"
            . "URL: [Zenodo DOI once published]\n"
            . "Short description: " . ($rawAtom['claim'] ?? '') . "\n\n"
            . "This links the published atom to a researcher identity, strengthening citation authority.";
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveDestinations(object $brand, array $options): array
    {
        $automated = self::CORE_DESTINATIONS;
        $manual    = self::MANUAL_DESTINATIONS;

        // Add sector-specific destinations
        $category = strtolower($brand->category ?? '');
        foreach (self::SECTOR_DESTINATIONS as $sector => $destinations) {
            if (str_contains($category, $sector)) {
                foreach ($destinations as $dest) {
                    if (!in_array($dest, $automated, true)) {
                        $automated[] = $dest;
                    }
                }
            }
        }

        // Allow override via options
        if (!empty($options['destinations'])) {
            $automated = $options['destinations'];
        }

        return ['automated' => $automated, 'manual' => $manual];
    }

    private function createJob(string $atomId, int $brandId, int $auditId, int $agencyId, string $destination): string
    {
        $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        DB::table('meridian_publication_jobs')->insert([
            'id'          => $id,
            'atom_id'     => $atomId,
            'brand_id'    => $brandId,
            'audit_id'    => $auditId,
            'agency_id'   => $agencyId,
            'destination' => $destination,
            'status'      => 'queued',
            'queued_at'   => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $id;
    }

    private function checkAndMarkPublished(string $atomId): void
    {
        $pending = DB::table('meridian_publication_jobs')
            ->where('atom_id', $atomId)
            ->whereIn('status', ['queued', 'running', 'retrying'])
            ->count();

        if ($pending === 0) {
            DB::table('meridian_atoms')
                ->where('id', $atomId)
                ->update(['status' => 'published', 'updated_at' => now()]);
        }
    }

    private function brandSlug(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
    }

    // -------------------------------------------------------------------------
    // Status check
    // -------------------------------------------------------------------------

    public function getStatus(string $atomId): array
    {
        $jobs = DB::table('meridian_publication_jobs')
            ->where('atom_id', $atomId)
            ->orderBy('destination')
            ->get();

        $manual = DB::table('meridian_manual_submissions')
            ->where('atom_id', $atomId)
            ->get();

        return [
            'atom_id'  => $atomId,
            'jobs'     => $jobs->map(fn($j) => [
                'destination'   => $j->destination,
                'status'        => $j->status,
                'result_url'    => $j->result_url,
                'result_doi'    => $j->result_doi,
                'attempt_count' => $j->attempt_count,
                'error_message' => $j->error_message,
                'completed_at'  => $j->completed_at,
            ])->toArray(),
            'manual'   => $manual->map(fn($m) => [
                'destination' => $m->destination,
                'status'      => $m->status,
                'brief'       => $m->brief,
            ])->toArray(),
        ];
    }
}
