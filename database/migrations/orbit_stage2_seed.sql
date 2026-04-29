-- =============================================================================
-- ORBIT Stage 2 — citation_platforms seed
-- =============================================================================
-- Seeds the citation_platforms table with the high-confidence anchors of the
-- v2 taxonomy. Covers:
--   • All 8 platforms with direct API adapters (search_method='api')
--   • All T1 tier anchors (encyclopaedic, academic, regulatory, standards, etc.)
--   • Key T2 sectoral platforms across the 7 prioritised sectors
--   • T3 classification anchors (forums, social, blogs, reviews, video)
--   • Special category exemplars (S.3 retail, S.4 code, S.5 PR, S.7 counter)
--
-- Long-tail platforms (regional press, niche trade, etc.) added later via the
-- admin CRUD UI — that's what the editor is for.
--
-- Idempotent: ON CONFLICT DO NOTHING on a uniqueness check by domain.
-- Run inside psql connected to the Railway database.
-- =============================================================================

BEGIN;

-- Add a unique partial index so re-running this seed is safe.
-- Pattern-only rows (domain IS NULL) are excluded from the unique check.
CREATE UNIQUE INDEX IF NOT EXISTS uq_citation_platforms_domain
    ON citation_platforms (domain)
    WHERE domain IS NOT NULL;

-- =============================================================================
-- TIER 1.1 — Encyclopaedic / structured knowledge
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Wikipedia',           'wikipedia.org',           'T1.1', 95, '{}', '{}', TRUE,  'api', 'https://en.wikipedia.org/w/api.php',          'none',    300, 0,        'days',   FALSE, 'Foundational training-data source. MediaWiki API, free.'),
    ('Wikidata',            'wikidata.org',            'T1.1', 95, '{}', '{}', TRUE,  'api', 'https://www.wikidata.org/w/api.php',          'none',    300, 0,        'days',   FALSE, 'Structured entity data. Free.'),
    ('Britannica',          'britannica.com',          'T1.1', 90, '{}', '{}', FALSE, NULL,  NULL,                                          NULL,      NULL,NULL,    'years',  FALSE, NULL),
    ('Stanford Encyclopedia of Philosophy', 'plato.stanford.edu', 'T1.1', 92, '{}', '{}', FALSE, NULL, NULL,                              NULL,      NULL,NULL,    'years',  FALSE, NULL),
    ('Schema.org',          'schema.org',              'T1.1', 88, '{}', '{structured-data}', FALSE, NULL, NULL,                          NULL,      NULL,NULL,    'static', FALSE, 'Structured-data spec source.'),
    ('Google Knowledge Graph','google.com/search',     'T1.1', 90, '{}', '{}', FALSE, NULL,  NULL,                                          NULL,      NULL,NULL,    'live',   FALSE, 'Read via Google Search results, not directly searchable for ORBIT.'),
    ('Bing Entity Search',  'bing.com',                'T1.1', 85, '{}', '{}', FALSE, NULL,  NULL,                                          NULL,      NULL,NULL,    'live',   FALSE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 1.2 — Peer-reviewed academic
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('PubMed',              'pubmed.ncbi.nlm.nih.gov', 'T1.2', 95, '{pharma,medical,biotech}', '{}', TRUE, 'api', 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/', 'none',     180, 0, 'weeks',  TRUE,  'NCBI E-utilities API. Free, 3 req/sec without API key, 10/sec with.'),
    ('Crossref',            'crossref.org',            'T1.2', 92, '{}', '{}', TRUE,  'api', 'https://api.crossref.org/works',              'none',    300, 0,        'weeks',  TRUE,  'DOI-indexed academic literature. Free with polite header.'),
    ('OpenAlex',            'openalex.org',            'T1.2', 90, '{}', '{}', TRUE,  'api', 'https://api.openalex.org/works',              'none',    600, 0,        'weeks',  TRUE,  'Open scholarly graph. Free, polite pool with email.'),
    ('Europe PMC',          'europepmc.org',           'T1.2', 90, '{pharma,medical,biotech}', '{}', FALSE, NULL, NULL,                     NULL,      NULL,NULL,    'weeks',  TRUE,  'Could add API later. ESearch-compatible.'),
    ('Cochrane Library',    'cochrane.org',            'T1.2', 95, '{pharma,medical}', '{systematic-review}', FALSE, NULL, NULL,            NULL,      NULL,NULL,    'months', TRUE,  NULL),
    ('Nature',              'nature.com',              'T1.2', 95, '{}', '{}', FALSE, NULL,  NULL,                                          NULL,      NULL,NULL,    'weeks',  TRUE,  'Reach via Crossref/OpenAlex.'),
    ('Science',             'science.org',             'T1.2', 95, '{}', '{}', FALSE, NULL,  NULL,                                          NULL,      NULL,NULL,    'weeks',  TRUE,  NULL),
    ('NEJM',                'nejm.org',                'T1.2', 95, '{pharma,medical}', '{}', FALSE, NULL, NULL,                             NULL,      NULL,NULL,    'weeks',  TRUE,  NULL),
    ('The Lancet',          'thelancet.com',           'T1.2', 95, '{pharma,medical}', '{}', FALSE, NULL, NULL,                             NULL,      NULL,NULL,    'weeks',  TRUE,  NULL),
    ('JAMA',                'jamanetwork.com',         'T1.2', 95, '{pharma,medical}', '{}', FALSE, NULL, NULL,                             NULL,      NULL,NULL,    'weeks',  TRUE,  NULL),
    ('Cell',                'cell.com',                'T1.2', 92, '{biotech,medical}', '{}', FALSE, NULL, NULL,                            NULL,      NULL,NULL,    'weeks',  TRUE,  NULL),
    ('PNAS',                'pnas.org',                'T1.2', 92, '{}', '{}', FALSE, NULL,  NULL,                                          NULL,      NULL,NULL,    'weeks',  TRUE,  NULL),
    ('BMJ',                 'bmj.com',                 'T1.2', 92, '{pharma,medical}', '{}', FALSE, NULL, NULL,                             NULL,      NULL,NULL,    'weeks',  TRUE,  NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 1.3 — DOI repositories / preprints
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Zenodo',              'zenodo.org',              'T1.3', 80, '{}', '{}', TRUE,  'api', 'https://zenodo.org/api/records',              'none',    60,  0,        'days',   TRUE,  'CERN-hosted DOI repository. Free.'),
    ('arXiv',               'arxiv.org',               'T1.3', 82, '{}', '{}', TRUE,  'api', 'http://export.arxiv.org/api/query',           'none',    60,  0,        'days',   TRUE,  'Preprint server. Free, polite throttling required (3 sec between requests).'),
    ('OSF',                 'osf.io',                  'T1.3', 75, '{}', '{}', FALSE, NULL,  NULL,                                          NULL,      NULL,NULL,    'days',   TRUE,  'Open Science Framework.'),
    ('figshare',            'figshare.com',            'T1.3', 75, '{}', '{}', FALSE, NULL,  NULL,                                          NULL,      NULL,NULL,    'days',   TRUE,  NULL),
    ('bioRxiv',             'biorxiv.org',             'T1.3', 80, '{biotech,medical,pharma}', '{}', FALSE, NULL, NULL,                     NULL,      NULL,NULL,    'days',   TRUE,  NULL),
    ('medRxiv',             'medrxiv.org',             'T1.3', 80, '{medical,pharma}', '{}', FALSE, NULL, NULL,                             NULL,      NULL,NULL,    'days',   TRUE,  NULL),
    ('ChemRxiv',            'chemrxiv.org',            'T1.3', 78, '{chemistry,pharma,cpg}', '{}', FALSE, NULL, NULL,                       NULL,      NULL,NULL,    'days',   TRUE,  NULL),
    ('SSRN',                'ssrn.com',                'T1.3', 75, '{finance,economics,legal}', '{}', FALSE, NULL, NULL,                    NULL,      NULL,NULL,    'days',   TRUE,  NULL),
    ('RePEc',               'repec.org',               'T1.3', 75, '{finance,economics}', '{}', FALSE, NULL, NULL,                          NULL,      NULL,NULL,    'days',   TRUE,  NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 1.4 — Government / regulatory
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('GOV.UK',              'gov.uk',                  'T1.4', 95, '{}', '{regulatory}', TRUE,  'site_search', 'https://www.gov.uk/search', 'none',    NULL,0,        'days',   TRUE,  'UK government umbrella. Brave site: search recommended.'),
    ('FDA',                 'fda.gov',                 'T1.4', 95, '{pharma,medical,cpg}', '{regulatory}', FALSE, NULL, NULL,               NULL,      NULL,NULL,    'days',   TRUE,  NULL),
    ('EMA',                 'ema.europa.eu',           'T1.4', 95, '{pharma,medical}', '{regulatory}', FALSE, NULL, NULL,                   NULL,      NULL,NULL,    'days',   TRUE,  NULL),
    ('MHRA',                'gov.uk/government/organisations/medicines-and-healthcare-products-regulatory-agency', 'T1.4', 95, '{pharma,medical}', '{regulatory}', FALSE, NULL, NULL, NULL, NULL,NULL, 'days', TRUE, 'UK pharma/devices regulator (lives on gov.uk).'),
    ('SEC EDGAR',           'sec.gov',                 'T1.4', 95, '{finance}', '{regulatory,registry}', FALSE, NULL, NULL,                 NULL,      NULL,NULL,    'days',   TRUE,  'US securities regulator + corporate filings.'),
    ('FCA',                 'fca.org.uk',              'T1.4', 95, '{finance}', '{regulatory}', FALSE, NULL, NULL,                          NULL,      NULL,NULL,    'days',   TRUE,  'UK Financial Conduct Authority.'),
    ('ESMA',                'esma.europa.eu',          'T1.4', 92, '{finance}', '{regulatory}', FALSE, NULL, NULL,                          NULL,      NULL,NULL,    'days',   TRUE,  NULL),
    ('NHS',                 'nhs.uk',                  'T1.4', 95, '{medical,pharma}', '{}', FALSE, NULL, NULL,                             NULL,      NULL,NULL,    'months', FALSE, NULL),
    ('CDC',                 'cdc.gov',                 'T1.4', 95, '{medical,pharma}', '{}', FALSE, NULL, NULL,                             NULL,      NULL,NULL,    'days',   FALSE, NULL),
    ('WHO',                 'who.int',                 'T1.4', 95, '{medical,pharma}', '{}', FALSE, NULL, NULL,                             NULL,      NULL,NULL,    'weeks',  FALSE, NULL),
    ('FTC',                 'ftc.gov',                 'T1.4', 92, '{}', '{regulatory}', FALSE, NULL, NULL,                                 NULL,      NULL,NULL,    'days',   TRUE,  NULL),
    ('CMA',                 'gov.uk/government/organisations/competition-and-markets-authority', 'T1.4', 92, '{}', '{regulatory}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, 'UK Competition + Markets Authority.'),
    ('ONS',                 'ons.gov.uk',              'T1.4', 92, '{finance,economics}', '{statistics}', FALSE, NULL, NULL,                NULL,      NULL,NULL,    'months', FALSE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 1.5 — Standards bodies
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('ISO',                 'iso.org',                 'T1.5', 90, '{}', '{standards}', FALSE, NULL, NULL, NULL, NULL, NULL, 'years', FALSE, NULL),
    ('IEEE',                'ieee.org',                'T1.5', 90, '{tech,saas}', '{standards}', FALSE, NULL, NULL, NULL, NULL, NULL, 'years', FALSE, NULL),
    ('W3C',                 'w3.org',                  'T1.5', 88, '{tech,saas}', '{standards}', FALSE, NULL, NULL, NULL, NULL, NULL, 'years', FALSE, NULL),
    ('IETF',                'ietf.org',                'T1.5', 88, '{tech,saas}', '{standards}', FALSE, NULL, NULL, NULL, NULL, NULL, 'years', FALSE, NULL),
    ('NICE',                'nice.org.uk',             'T1.5', 92, '{pharma,medical}', '{standards,clinical-guideline}', FALSE, NULL, NULL, NULL, NULL, NULL, 'years', FALSE, 'UK clinical guideline body.'),
    ('USPSTF',              'uspreventiveservicestaskforce.org', 'T1.5', 92, '{medical}', '{standards,clinical-guideline}', FALSE, NULL, NULL, NULL, NULL, NULL, 'years', FALSE, NULL),
    ('IFRS Foundation',     'ifrs.org',                'T1.5', 90, '{finance}', '{standards}', FALSE, NULL, NULL, NULL, NULL, NULL, 'years', FALSE, NULL),
    ('FASB',                'fasb.org',                'T1.5', 90, '{finance}', '{standards}', FALSE, NULL, NULL, NULL, NULL, NULL, 'years', FALSE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 1.7 — Corporate registries
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Companies House',     'find-and-update.company-information.service.gov.uk', 'T1.7', 90, '{}', '{registry}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', FALSE, 'UK companies registry.'),
    ('OpenCorporates',      'opencorporates.com',      'T1.7', 88, '{}', '{registry}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', FALSE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 1.9 — Business intelligence (entity verification)
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Crunchbase',          'crunchbase.com',          'T1.9', 75, '{tech,saas,finance}', '{entity-verification}', FALSE, NULL, NULL, NULL, NULL, NULL, 'weeks', FALSE, NULL),
    ('PitchBook',           'pitchbook.com',           'T1.9', 78, '{finance,tech}', '{entity-verification,paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'weeks', FALSE, NULL),
    ('Dun & Bradstreet',    'dnb.com',                 'T1.9', 75, '{}', '{entity-verification}', FALSE, NULL, NULL, NULL, NULL, NULL, 'weeks', FALSE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 2.1 — Top financial press
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Financial Times',     'ft.com',                  'T2.1', 70, '{finance,business}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Wall Street Journal', 'wsj.com',                 'T2.1', 70, '{finance,business}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Bloomberg',           'bloomberg.com',           'T2.1', 70, '{finance,business}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Reuters',             'reuters.com',             'T2.1', 70, '{finance,business}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('The Economist',       'economist.com',           'T2.1', 68, '{finance,business}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'weeks', TRUE, NULL),
    ('Forbes',              'forbes.com',              'T2.1', 55, '{business}', '{contributor-network-mixed-quality}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, 'Mixed quality due to contributor network.'),
    ('Fortune',             'fortune.com',             'T2.1', 65, '{business}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Barron''s',           'barrons.com',             'T2.1', 65, '{finance}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 2.2 — Top general press
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('New York Times',      'nytimes.com',             'T2.2', 68, '{}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Washington Post',     'washingtonpost.com',      'T2.2', 65, '{}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('The Guardian',        'theguardian.com',         'T2.2', 65, '{}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('The Telegraph',       'telegraph.co.uk',         'T2.2', 60, '{}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('The Times (UK)',      'thetimes.co.uk',          'T2.2', 62, '{}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('The Atlantic',        'theatlantic.com',         'T2.2', 62, '{}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'weeks', TRUE, NULL),
    ('The New Yorker',      'newyorker.com',           'T2.2', 62, '{}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'weeks', TRUE, NULL),
    ('The Conversation',    'theconversation.com',     'T2.2', 65, '{}', '{academic-authored}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, 'Academic-authored journalism.')
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 2.3 — Wire services
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Associated Press',    'apnews.com',              'T2.3', 70, '{}', '{wire}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('AFP',                 'afp.com',                 'T2.3', 68, '{}', '{wire}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('PA Media',            'pamediagroup.com',        'T2.3', 65, '{}', '{wire}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 2.4 — Analyst / consultancy
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Gartner',             'gartner.com',             'T2.4', 65, '{tech,saas,business}', '{paywalled,analyst}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Forrester',           'forrester.com',           'T2.4', 65, '{tech,saas,business}', '{paywalled,analyst}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('IDC',                 'idc.com',                 'T2.4', 62, '{tech,saas}', '{paywalled,analyst}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('McKinsey',            'mckinsey.com',            'T2.4', 65, '{business}', '{consultancy}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('BCG',                 'bcg.com',                 'T2.4', 62, '{business}', '{consultancy}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Bain',                'bain.com',                'T2.4', 62, '{business}', '{consultancy}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Deloitte Insights',   'deloitte.com',            'T2.4', 60, '{business}', '{consultancy}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('PwC',                 'pwc.com',                 'T2.4', 60, '{business}', '{consultancy}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('EY',                  'ey.com',                  'T2.4', 58, '{business}', '{consultancy}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('KPMG',                'kpmg.com',                'T2.4', 58, '{business}', '{consultancy}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Nielsen',             'nielsen.com',             'T2.4', 60, '{cpg,beauty,business}', '{research}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Kantar',              'kantar.com',              'T2.4', 60, '{cpg,beauty,business}', '{research}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Mintel',              'mintel.com',              'T2.4', 62, '{cpg,beauty,retail}', '{paywalled,research}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Euromonitor',         'euromonitor.com',         'T2.4', 62, '{cpg,beauty,retail}', '{paywalled,research}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 2.5 — Sector trade press (anchors per sector)
-- =============================================================================
-- BEAUTY
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Allure',              'allure.com',              'T2.5', 60, '{beauty}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('WWD Beauty',          'wwd.com',                 'T2.5', 62, '{beauty,fashion}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('Cosmetics Business',  'cosmeticsbusiness.com',   'T2.5', 60, '{beauty}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('Beauty Independent',  'beautyindependent.com',   'T2.5', 58, '{beauty}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('Glossy',              'glossy.co',               'T2.5', 58, '{beauty,fashion}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('INCIdecoder',         'incidecoder.com',         'T2.5', 65, '{beauty}', '{ingredient-database}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', FALSE, 'Cosmetic ingredient analysis.'),
    ('Sephora',             'sephora.com',             'T2.5', 55, '{beauty}', '{retail-with-reviews}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, 'Reviews + retail. Tagged retail too.'),
    ('Ulta Beauty',         'ulta.com',                'T2.5', 50, '{beauty}', '{retail-with-reviews}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- TECH / SAAS
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('TechCrunch',          'techcrunch.com',          'T2.5', 60, '{tech,saas}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('The Verge',           'theverge.com',            'T2.5', 60, '{tech,consumer-electronics}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Ars Technica',        'arstechnica.com',         'T2.5', 65, '{tech,consumer-electronics}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Wired',               'wired.com',               'T2.5', 62, '{tech}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Engadget',            'engadget.com',            'T2.5', 58, '{tech,consumer-electronics}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('TechRadar',           'techradar.com',           'T2.5', 55, '{tech,consumer-electronics}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('CNET',                'cnet.com',                'T2.5', 55, '{tech,consumer-electronics}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Tom''s Hardware',     'tomshardware.com',        'T2.5', 60, '{tech,consumer-electronics}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('AnandTech',           'anandtech.com',           'T2.5', 65, '{tech,consumer-electronics}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('GSMArena',            'gsmarena.com',            'T2.5', 65, '{consumer-electronics}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('DPReview',            'dpreview.com',            'T2.5', 65, '{consumer-electronics}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Notebookcheck',       'notebookcheck.net',       'T2.5', 60, '{consumer-electronics}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- PHARMA / HEALTH TRADE
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Stat News',           'statnews.com',            'T2.5', 65, '{pharma,medical,biotech}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Endpoints News',      'endpts.com',              'T2.5', 65, '{pharma,biotech}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('FiercePharma',        'fiercepharma.com',        'T2.5', 60, '{pharma}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('ClinicalTrials.gov',  'clinicaltrials.gov',      'T2.5', 88, '{pharma,medical}', '{registry}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', FALSE, 'Trial registry — high authority for trial-specific claims.')
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- AUTOMOTIVE
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Edmunds',             'edmunds.com',             'T2.5', 60, '{automotive}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Kelley Blue Book',    'kbb.com',                 'T2.5', 60, '{automotive}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Auto Express',        'autoexpress.co.uk',       'T2.5', 55, '{automotive}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('What Car?',           'whatcar.com',             'T2.5', 58, '{automotive}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Carwow',              'carwow.co.uk',            'T2.5', 55, '{automotive}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- FINANCE / BANKING
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Morningstar',         'morningstar.com',         'T2.5', 65, '{finance}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Yahoo Finance',       'finance.yahoo.com',       'T2.5', 50, '{finance}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('S&P Global',          'spglobal.com',            'T2.5', 70, '{finance}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('The Banker',          'thebanker.com',           'T2.5', 62, '{finance}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('Euromoney',           'euromoney.com',           'T2.5', 62, '{finance}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- CPG / RETAIL TRADE
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('AdAge',               'adage.com',               'T2.5', 60, '{cpg,beauty,marketing}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Adweek',              'adweek.com',              'T2.5', 58, '{cpg,marketing}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Marketing Week',      'marketingweek.com',       'T2.5', 58, '{marketing}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Retail Dive',         'retaildive.com',          'T2.5', 58, '{retail,cpg}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Modern Retail',       'modernretail.co',         'T2.5', 55, '{retail,cpg}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 2.5b — Authoritative newsletters
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Stratechery',         'stratechery.com',         'T2.5', 65, '{tech,saas,business}', '{newsletter,author-driven}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, 'Ben Thompson.'),
    ('Doomberg',            'doomberg.substack.com',   'T2.5', 60, '{finance,energy}', '{newsletter,author-driven}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('Platformer',          'platformer.news',         'T2.5', 60, '{tech,saas}', '{newsletter}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('The Information',     'theinformation.com',      'T2.5', 65, '{tech,saas}', '{paywalled,newsletter}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 2.6 — Professional reviews
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Consumer Reports',    'consumerreports.org',     'T2.6', 70, '{cpg,consumer-electronics,automotive}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Which?',              'which.co.uk',             'T2.6', 70, '{cpg,consumer-electronics,automotive}', '{paywalled}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Wirecutter',          'nytimes.com/wirecutter',  'T2.6', 68, '{consumer-electronics,cpg}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('RTINGS',              'rtings.com',              'T2.6', 65, '{consumer-electronics}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('NerdWallet',          'nerdwallet.com',          'T2.6', 58, '{finance}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL),
    ('Bankrate',            'bankrate.com',            'T2.6', 58, '{finance}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 2.7 — Health authority (consumer-facing)
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Healthline',          'healthline.com',          'T2.7', 60, '{medical,pharma}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', FALSE, NULL),
    ('WebMD',               'webmd.com',               'T2.7', 58, '{medical,pharma}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', FALSE, NULL),
    ('Mayo Clinic',         'mayoclinic.org',          'T2.7', 70, '{medical,pharma}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'years', FALSE, NULL),
    ('Cleveland Clinic',    'clevelandclinic.org',     'T2.7', 68, '{medical,pharma}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'years', FALSE, NULL),
    ('Johns Hopkins Medicine','hopkinsmedicine.org',   'T2.7', 70, '{medical,pharma}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'years', FALSE, NULL),
    ('Drugs.com',           'drugs.com',               'T2.7', 62, '{pharma,medical}', '{}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 2.8 — B2B platforms (software reviews + employer reviews)
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('G2',                  'g2.com',                  'T2.8', 60, '{saas,tech}', '{review-platform}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Capterra',            'capterra.com',            'T2.8', 55, '{saas,tech}', '{review-platform}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('TrustRadius',         'trustradius.com',         'T2.8', 55, '{saas,tech}', '{review-platform}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Glassdoor',           'glassdoor.com',           'T2.8', 55, '{}', '{employer-review}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 2.10 — Data providers
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Statista',            'statista.com',            'T2.10', 60, '{}', '{paywalled,data}', FALSE, NULL, NULL, NULL, NULL, NULL, 'months', FALSE, NULL),
    ('Moody''s',            'moodys.com',              'T2.10', 70, '{finance}', '{paywalled,ratings}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('Fitch Ratings',       'fitchratings.com',        'T2.10', 70, '{finance}', '{paywalled,ratings}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('MSCI',                'msci.com',                'T2.10', 65, '{finance}', '{paywalled,data}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 3.1 — Community / forums
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Reddit',              'reddit.com',              'T3.1', 35, '{}', '{community}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, 'Reach via Brave site: search for ORBIT.'),
    ('Stack Overflow',      'stackoverflow.com',       'T3.1', 40, '{tech,saas}', '{community}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Stack Exchange',      'stackexchange.com',       'T3.1', 38, '{}', '{community}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Quora',               'quora.com',               'T3.1', 25, '{}', '{community}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Hacker News',         'news.ycombinator.com',    'T3.1', 38, '{tech,saas}', '{community}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 3.2 — Social media
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('X / Twitter',         'twitter.com',             'T3.2', 30, '{}', '{social}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('LinkedIn',            'linkedin.com',            'T3.2', 35, '{business}', '{social}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Instagram',           'instagram.com',           'T3.2', 25, '{beauty,fashion,cpg}', '{social,social-commerce}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('TikTok',              'tiktok.com',              'T3.2', 25, '{beauty,fashion,cpg}', '{social,social-commerce}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Pinterest',           'pinterest.com',           'T3.2', 25, '{beauty,fashion,cpg,home}', '{social,social-commerce}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 3.3 — Blogging platforms
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Medium',              'medium.com',              'T3.3', 30, '{}', '{blogging}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Substack',            'substack.com',            'T3.3', 35, '{}', '{newsletter,blogging}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Dev.to',              'dev.to',                  'T3.3', 35, '{tech,saas}', '{blogging}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 3.5 — Video platforms (transcripts only)
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('YouTube',             'youtube.com',             'T3.5', 30, '{}', '{video,transcripts-only}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, 'LLMs read transcripts only — audio not ingested.'),
    ('Vimeo',               'vimeo.com',               'T3.5', 25, '{}', '{video,transcripts-only}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 3.7 — Local / service reviews
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Trustpilot',          'trustpilot.com',          'T3.7', 35, '{}', '{review-platform}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Yelp',                'yelp.com',                'T3.7', 30, '{}', '{review-platform}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('BBB',                 'bbb.org',                 'T3.7', 35, '{}', '{review-platform}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Google Reviews',      'google.com/maps',         'T3.7', 30, '{}', '{review-platform}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- TIER 3.9 — General web (uncategorised fallback — used as default classification)
-- =============================================================================
-- Pattern-only entry for fallback. Anything not matching a domain hits this.
-- Idempotent via WHERE NOT EXISTS check on the pattern (unique-on-domain index doesn't cover NULL domains).
INSERT INTO citation_platforms
    (platform_name, domain, pattern, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
SELECT 'General Web (fallback)', NULL, '.*', 'T3.9', 15, '{}'::TEXT[], '{fallback}'::TEXT[], FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, 'Default tier when no other classification matches. Never returns "unknown".'
WHERE NOT EXISTS (
    SELECT 1 FROM citation_platforms WHERE pattern = '.*' AND domain IS NULL
);

-- =============================================================================
-- TIER 3.10 — Aggregators
-- =============================================================================
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Yahoo News',          'news.yahoo.com',          'T3.10', 30, '{}', '{aggregator}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('MSN',                 'msn.com',                 'T3.10', 28, '{}', '{aggregator}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Apple News',          'apple.news',              'T3.10', 30, '{}', '{aggregator}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Flipboard',           'flipboard.com',           'T3.10', 25, '{}', '{aggregator}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- =============================================================================
-- SPECIAL CATEGORIES
-- =============================================================================

-- S.3 — Retail / marketplace
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Amazon',              'amazon.com',              'T2.5', 45, '{retail,cpg,consumer-electronics}', '{retail,marketplace}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('eBay',                'ebay.com',                'T3.7', 30, '{retail}', '{retail,marketplace}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Walmart',             'walmart.com',             'T2.5', 45, '{retail,cpg}', '{retail}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL),
    ('Target',              'target.com',              'T2.5', 45, '{retail,cpg}', '{retail}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- S.4 — Code / datasets
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('GitHub',              'github.com',              'T1.3', 75, '{tech,saas}', '{code,datasets}', TRUE, 'api', 'https://api.github.com/search/repositories', 'bearer', 30, 0, 'live', FALSE, 'GitHub Search API. Bearer token required for higher rate limits (5000/hr authenticated, 60/hr unauthenticated).'),
    ('GitLab',              'gitlab.com',              'T1.3', 70, '{tech,saas}', '{code,datasets}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', FALSE, NULL),
    ('Hugging Face',        'huggingface.co',          'T1.3', 72, '{ai,ml}', '{code,datasets,models}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', FALSE, NULL),
    ('Kaggle',              'kaggle.com',              'T1.3', 68, '{ai,ml}', '{datasets}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', FALSE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- S.5 — Press release distribution
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('PR Newswire',         'prnewswire.com',          'T2.5', 50, '{}', '{press-release}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', FALSE, 'Press release distribution. Treat as quasi-brand-owned — verbatim corporate.'),
    ('Business Wire',       'businesswire.com',        'T2.5', 50, '{}', '{press-release}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', FALSE, NULL),
    ('GlobeNewswire',       'globenewswire.com',       'T2.5', 48, '{}', '{press-release}', FALSE, NULL, NULL, NULL, NULL, NULL, 'live', FALSE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

-- S.7 — Counter-evidence sources (used to penalise candidate_score when found)
INSERT INTO citation_platforms
    (platform_name, domain, tier, score_base, sector, tags, searchable, search_method, search_endpoint, api_auth_type, rate_limit_qpm, cost_per_query, typical_recency, sentiment_relevance, notes)
VALUES
    ('Snopes',              'snopes.com',              'T2.5', 70, '{}', '{counter-evidence,fact-check}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, 'Fact-checking — high authority but content matters: high-score if fact-check supports claim, sentiment_penalty applies if it refutes.'),
    ('FactCheck.org',       'factcheck.org',           'T2.5', 70, '{}', '{counter-evidence,fact-check}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL),
    ('PolitiFact',          'politifact.com',          'T2.5', 65, '{}', '{counter-evidence,fact-check}', FALSE, NULL, NULL, NULL, NULL, NULL, 'days', TRUE, NULL)
ON CONFLICT (domain) WHERE domain IS NOT NULL DO NOTHING;

COMMIT;

-- =============================================================================
-- Verification queries
-- =============================================================================
-- SELECT tier, COUNT(*) FROM citation_platforms GROUP BY tier ORDER BY tier;
-- SELECT COUNT(*) FROM citation_platforms;
-- SELECT COUNT(*) FROM citation_platforms WHERE searchable = TRUE;
-- SELECT platform_name, search_method, search_endpoint FROM citation_platforms WHERE searchable = TRUE ORDER BY tier, platform_name;
