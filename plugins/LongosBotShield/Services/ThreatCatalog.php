<?php

namespace Plugin\LongosBotShield\Services;

/**
 * High-confidence, release-owned detection catalog.
 *
 * Tenant settings are additive. Keeping the baseline in code means a LONGOS
 * upgrade can expand coverage without overwriting a merchant's local lists.
 */
class ThreatCatalog
{
    /**
     * High-confidence platform and cloud ASNs. Consumer access networks such
     * as Google Fiber/WiFi are deliberately excluded.
     *
     * @var array<string, list<string>>
     */
    public const PLATFORM_ASNS = [
        'meta_facebook' => ['AS32934', 'AS54115', 'AS63293'],
        'tiktok'        => ['AS138699', 'AS396986'],
        'amazon'        => ['AS7224', 'AS8987', 'AS14618', 'AS16509', 'AS62785'],
        'google'        => ['AS15169', 'AS19527', 'AS36040', 'AS396982', 'AS139070', 'AS139190', 'AS45566'],
    ];

    /** @var list<string> */
    public const HOSTING_ASNS = [
        // Transit, backbone, hosting and cloud platforms.
        'AS174', 'AS801', 'AS3356', 'AS3598', 'AS6939', 'AS7224',
        'AS8068', 'AS8069', 'AS8070', 'AS8075', 'AS8987', 'AS9009',
        'AS12076', 'AS12876', 'AS13335', 'AS14061', 'AS14618', 'AS15169', 'AS16276',
        'AS16509', 'AS16625', 'AS19551', 'AS19994', 'AS20473', 'AS20940',
        'AS19527', 'AS21342', 'AS213230', 'AS24429', 'AS24940', 'AS26496', 'AS28753',
        'AS29802', 'AS31898', 'AS32934', 'AS36351', 'AS36352', 'AS37963',
        'AS45090', 'AS45102', 'AS46606', 'AS47583', 'AS51167', 'AS54113',
        'AS54825', 'AS55286', 'AS55990', 'AS58593', 'AS60068', 'AS60781', 'AS62240',
        'AS63949', 'AS8100', 'AS132203', 'AS136907', 'AS139070', 'AS139190',
        'AS212238', 'AS394089', 'AS396982', 'AS43515',
    ];

    /** @var list<string> */
    public const ORGANIZATION_KEYWORDS = [
        'facebook', 'meta platforms', 'google llc', 'google cloud', 'tiktok', 'bytedance',
        'amazon technologies', 'amazon data services', 'amazon.com', 'microsoft azure',
        'microsoft corporation', 'digitalocean', 'linode', 'akamai', 'ovh', 'ovhcloud',
        'hetzner', 'choopa', 'vultr', 'm247', 'm2f', 'cogent', 'lumen', 'level 3',
        'hurricane electric', 'oracle cloud', 'oracle corporation', 'alibaba cloud',
        'alicloud', 'tencent cloud', 'huawei cloud', 'cloudflare', 'fastly', 'leaseweb',
        'contabo', 'hostinger', 'scaleway', 'online s.a.s', 'online.net', 'equinix metal',
        'packet host', 'softlayer', 'ibm cloud', 'rackspace', 'godaddy', 'colo crossing',
        'colocrossing', 'quadranet', 'hivelocity', 'clouvider', 'serverius', 'psychz',
        'datacamp limited', 'cdn77', 'netcup', 'ionos', '1&1 internet',
        'cherry servers', 'latitude.sh', 'kamatera', 'phoenixnap', 'edgecast',
        'verizon digital media', 'zenlayer', 'cloudcone', 'upcloud', 'fly.io',
        'render.com', 'railway', 'netlify', 'vercel', 'github', 'gitlab', 'firebase',
        'heroku', 'cloudways', 'hostwinds', 'interserver', 'namecheap', 'greencloud',
        'buyvm', 'frantech', 'path network', 'ddos-guard', 'stormwall', 'imperva',
        'incapsula', 'hosting', 'data center', 'datacenter', 'cloud compute',
        'virtual private server', 'dedicated server', 'colocation', 'colo facility',
    ];

    /**
     * High-confidence proxy-network owner names that can appear in IPInfo
     * Lite ASN identity. Generic words such as "vpn" and "proxy" are omitted
     * to avoid matching unrelated consumer ISPs or business names.
     *
     * @var list<string>
     */
    public const PROXY_ORGANIZATION_KEYWORDS = [
        'bright data', 'luminati networks', 'oxylabs', 'smartproxy', 'decodo',
        'soax', 'netnut', 'packetstream', 'iproyal', 'webshare software',
        'geonode', 'proxy-cheap', 'mullvad', 'nordvpn', 'expressvpn',
        'surfshark', 'proton vpn', 'private internet access', 'windscribe',
        'ipvanish', 'purevpn', 'cyberghost',
    ];

    /** @var list<string> */
    public const AUTOMATION_MARKERS = [
        'headlesschrome', 'headless chrome', 'phantomjs', 'slimerjs', 'puppeteer',
        'playwright', 'selenium', 'webdriver', 'nightmare', 'electron/', 'cypress/',
        'chrome-lighthouse', 'htmlunit', 'splash/', 'qtwebengine', 'javafx',
        'callphantom', 'geb/', 'browserless', 'chromedp', 'rod-browser',
    ];

    /** @var list<string> */
    public const SCRIPT_CLIENT_MARKERS = [
        'python-requests', 'python-urllib', 'python-httpx', 'aiohttp', 'scrapy',
        'curl/', 'libcurl', 'wget/', 'go-http-client', 'fasthttp', 'libwww-perl',
        'apache-httpclient', 'java/', 'httpclient', 'okhttp', 'axios/', 'node-fetch',
        'undici', 'got/', 'guzzlehttp', 'mechanize', 'restsharp', 'postmanruntime',
        'insomnia/', 'powershell/', 'winhttp', 'ruby/', 'php/', 'http.rb/',
    ];

    /** @var array<string, list<string>> */
    public const CRAWLER_MARKERS = [
        'google' => [
            'googlebot', 'googleother', 'google-inspectiontool', 'adsbot-google',
            'mediapartners-google', 'feedfetcher-google', 'storebot-google',
            'google-read-aloud', 'google-cloudvertexbot', 'google-safety',
            'apis-google', 'google-cws', 'google-gemininotebook', 'google-notebooklm',
            'google-agent', 'googlemessages', 'google-pinpoint', 'googleproducer',
            'google-site-verification', 'google-speakr',
        ],
        'meta' => [
            'facebookexternalhit', 'facebot', 'meta-externalagent',
            'meta-externalfetcher', 'facebookcatalog',
        ],
        'microsoft' => ['bingbot', 'bingpreview', 'adidxbot', 'msnbot', 'microsoftpreview'],
        'tiktok'    => ['tiktokspider', 'bytespider', 'bytedancespider'],
        'search'    => [
            'applebot', 'duckduckbot', 'yandexbot', 'baiduspider', 'petalbot',
            'sogou web spider', '360spider', 'qwantbot', 'seznambot', 'naverbot',
        ],
        'ai' => [
            'gptbot', 'chatgpt-user', 'oai-searchbot', 'claudebot', 'claude-user',
            'oai-adsbot', 'anthropic-ai', 'perplexitybot', 'perplexity-user', 'ccbot', 'cohere-ai',
            'diffbot', 'youbot', 'amazonbot', 'ai2bot', 'omgili', 'imagesiftbot',
        ],
        'seo' => [
            'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'dataforseobot',
            'serpstatbot', 'blexbot', 'seekportbot', 'rogerbot', 'screaming frog',
            'sitebulb', 'seobilitybot', 'linkdexbot', 'megaindex',
        ],
        'social' => [
            'twitterbot', 'linkedinbot', 'pinterestbot', 'slackbot', 'discordbot',
            'telegrambot', 'vkshare', 'skypeuripreview',
        ],
        'archive' => ['ia_archiver', 'archive.org_bot', 'commoncrawl'],
        'monitor' => [
            'uptimerobot', 'statuscake', 'pingdom', 'site24x7', 'better uptime',
            'checkly', 'newrelicpinger', 'datadog synthetics',
        ],
    ];

    /** @return list<string> */
    public static function crawlerMarkers(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::CRAWLER_MARKERS))));
    }

    /** @return list<string> */
    public static function platformAsns(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::PLATFORM_ASNS))));
    }
}
