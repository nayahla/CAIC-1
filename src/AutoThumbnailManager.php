<?php

namespace caic;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\fields\Assets as AssetsField;
use craft\fields\data\LinkData;
use craft\helpers\Assets as AssetsHelper;
use craft\services\Elements;
use yii\base\Event;
use yii\helpers\FileHelper;

class AutoThumbnailManager
{
    private const AUTO_FILENAME_PREFIX = 'auto-thumb';
    private const MAX_DOWNLOAD_BYTES = 10485760;
    private const ENTRY_SECTIONS = ['resources', 'news'];

    private static bool $registered = false;
    private static array $activeEntries = [];

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            static function(ElementEvent $event): void {
                self::handleAfterSave($event);
            },
        );

        self::$registered = true;
    }

    private static function handleAfterSave(ElementEvent $event): void
    {
        $element = $event->element;

        if (!$element instanceof Entry || !$element->getIsCanonical() || $element->getIsDraft() || $element->getIsRevision()) {
            return;
        }

        $sectionHandle = $element->section?->handle;
        if (!$sectionHandle || !in_array($sectionHandle, self::ENTRY_SECTIONS, true)) {
            return;
        }

        $imageFieldHandle = self::imageFieldHandle($element);
        if (!$imageFieldHandle || !$element->getFieldLayout()?->getFieldByHandle('externalLink')) {
            return;
        }

        $entryKey = (string)($element->canonicalId ?: $element->id);
        if (isset(self::$activeEntries[$entryKey])) {
            return;
        }

        self::$activeEntries[$entryKey] = true;

        try {
            self::syncEntryMetadata($element, $imageFieldHandle, $event->isNew);
        } catch (\Throwable $exception) {
            Craft::warning(
                sprintf('External metadata sync failed for entry %s: %s', $element->id, $exception->getMessage()),
                __METHOD__,
            );
        } finally {
            unset(self::$activeEntries[$entryKey]);
        }
    }

    private static function syncEntryMetadata(Entry $entry, string $imageFieldHandle, bool $isNew): void
    {
        $externalUrl = self::externalUrl($entry->getFieldValue('externalLink'));
        if (!$externalUrl) {
            return;
        }

        $currentAsset = self::currentAsset($entry, $imageFieldHandle);
        $autoManagedAsset = $currentAsset && self::isAutoManagedAsset($currentAsset, $entry, $imageFieldHandle);
        $shouldSyncImage = true;

        if ($currentAsset && !$autoManagedAsset) {
            $shouldSyncImage = false;
        } elseif ($autoManagedAsset && !$entry->isFieldDirty('externalLink')) {
            $shouldSyncImage = false;
        } elseif (!$isNew && !$currentAsset && !$entry->isFieldDirty('externalLink') && !$entry->isFieldDirty($imageFieldHandle)) {
            $shouldSyncImage = false;
        }

        $shouldSyncDate = self::shouldSyncPostDate($entry, $isNew);
        if (!$shouldSyncImage && !$shouldSyncDate) {
            return;
        }

        $download = null;
        $newAsset = null;
        $shouldSaveEntry = false;

        if ($shouldSyncDate) {
            $publishedDate = self::discoverPublishedDate($externalUrl);
            if ($publishedDate && !self::timestampsClose($entry->postDate, $publishedDate, 60)) {
                $entry->postDate = \DateTime::createFromInterface($publishedDate);
                $shouldSaveEntry = true;
            }
        }

        try {
            if ($shouldSyncImage) {
                $imageUrl = self::discoverImageUrl($externalUrl);
                if ($imageUrl) {
                    $download = self::downloadImage($imageUrl);
                    if ($download) {
                        $newAsset = self::createAsset($download, $entry, $imageFieldHandle);
                        if ($newAsset) {
                            $entry->setFieldValue($imageFieldHandle, [$newAsset->id]);
                            $shouldSaveEntry = true;
                        }
                    }
                }
            }

            if (!$shouldSaveEntry) {
                return;
            }

            $saved = Craft::$app->getElements()->saveElement(
                $entry,
                runValidation: false,
                propagate: false,
                updateSearchIndex: false,
                forceTouch: false,
                crossSiteValidate: false,
                saveContent: true,
            );

            if (!$saved) {
                if ($newAsset) {
                    Craft::$app->getElements()->deleteElement($newAsset);
                }

                Craft::warning(
                    sprintf('Unable to save synced metadata for entry %s: %s', $entry->id, implode(', ', $entry->getErrorSummary(true))),
                    __METHOD__,
                );
                return;
            }

            if ($autoManagedAsset && $currentAsset && $newAsset && $currentAsset->id !== $newAsset->id) {
                Craft::$app->getElements()->deleteElement($currentAsset);
            }
        } finally {
            if ($download && is_file($download['tempFilePath'])) {
                FileHelper::unlink($download['tempFilePath']);
            }
        }
    }

    private static function imageFieldHandle(Entry $entry): ?string
    {
        foreach (['resourceImage', 'newsImage'] as $fieldHandle) {
            if ($entry->getFieldLayout()?->getFieldByHandle($fieldHandle)) {
                return $fieldHandle;
            }
        }

        return null;
    }

    private static function currentAsset(Entry $entry, string $fieldHandle): ?Asset
    {
        $value = $entry->getFieldValue($fieldHandle);

        if (is_object($value) && method_exists($value, 'one')) {
            return $value->one();
        }

        return $value instanceof Asset ? $value : null;
    }

    private static function externalUrl(mixed $linkValue): ?string
    {
        if ($linkValue instanceof LinkData) {
            if ($linkValue->getType() !== 'url') {
                return null;
            }

            return self::normalizeHttpUrl($linkValue->getUrl());
        }

        if (is_string($linkValue)) {
            return self::normalizeHttpUrl($linkValue);
        }

        if (is_array($linkValue) && isset($linkValue['value']) && is_string($linkValue['value'])) {
            return self::normalizeHttpUrl($linkValue['value']);
        }

        return null;
    }

    private static function normalizeHttpUrl(?string $url): ?string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private static function discoverImageUrl(string $pageUrl): ?string
    {
        if (self::looksLikeImageUrl($pageUrl)) {
            return $pageUrl;
        }

        $response = self::httpGet($pageUrl, 'text/html,application/xhtml+xml');
        if (!$response) {
            return null;
        }

        if (str_starts_with(strtolower($response['contentType'] ?? ''), 'image/')) {
            return $response['effectiveUrl'];
        }

        $metaImage = self::extractMetaImageUrl($response['body'] ?? '', $response['effectiveUrl'] ?? $pageUrl);
        if ($metaImage) {
            return $metaImage;
        }

        return self::extractContentImageUrl($response['body'] ?? '', $response['effectiveUrl'] ?? $pageUrl);
    }

    private static function discoverPublishedDate(string $pageUrl): ?\DateTimeImmutable
    {
        $response = self::httpGet($pageUrl, 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
        if (!$response) {
            return null;
        }

        $headerDate = self::extractHeaderDate($response['headers'] ?? []);
        $contentType = strtolower((string)($response['contentType'] ?? ''));

        if (!self::isHtmlContentType($contentType)) {
            return $headerDate;
        }

        $html = (string)($response['body'] ?? '');
        $candidates = [
            ...self::extractMetaDateCandidates($html),
            ...self::extractJsonLdDateCandidates($html),
            ...self::extractTimeDateCandidates($html),
        ];

        foreach ($candidates as $candidate) {
            $publishedDate = self::parsePublishedDate($candidate);
            if ($publishedDate) {
                return $publishedDate;
            }
        }

        return $headerDate;
    }

    private static function extractMetaDateCandidates(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $primaryKeys = [
            'article:published_time',
            'og:article:published_time',
            'og:published_time',
            'published_time',
            'publish-date',
            'publish_date',
            'pubdate',
            'datepublished',
            'date_published',
            'citation_publication_date',
            'parsely-pub-date',
            'sailthru.date',
            'dc.date',
            'dc.date.issued',
            'dc.date.created',
            'date',
        ];
        $fallbackKeys = [
            'article:modified_time',
            'og:updated_time',
            'lastmod',
            'dateupdated',
            'datemodified',
            'modified_time',
        ];
        $primary = [];
        $fallback = [];

        if (class_exists(\DOMDocument::class)) {
            $dom = new \DOMDocument();
            $previousState = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML($html);
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);

            if ($loaded) {
                foreach ($dom->getElementsByTagName('meta') as $metaTag) {
                    $content = trim((string)$metaTag->getAttribute('content'));
                    if ($content === '') {
                        continue;
                    }

                    $keys = array_filter([
                        strtolower(trim((string)$metaTag->getAttribute('property'))),
                        strtolower(trim((string)$metaTag->getAttribute('name'))),
                        strtolower(trim((string)$metaTag->getAttribute('itemprop'))),
                    ]);

                    foreach ($keys as $key) {
                        if (in_array($key, $primaryKeys, true)) {
                            $primary[] = $content;
                            continue 2;
                        }

                        if (in_array($key, $fallbackKeys, true)) {
                            $fallback[] = $content;
                            continue 2;
                        }
                    }
                }
            }
        }

        if (!$primary && !$fallback && preg_match_all('/<(?:meta)[^>]+(?:property|name|itemprop)=["\']([^"\']+)["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = strtolower(trim($match[1]));
                $content = trim($match[2]);

                if (in_array($key, $primaryKeys, true)) {
                    $primary[] = $content;
                } elseif (in_array($key, $fallbackKeys, true)) {
                    $fallback[] = $content;
                }
            }
        }

        return [...$primary, ...$fallback];
    }

    private static function extractJsonLdDateCandidates(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $candidates = [];
        if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return $candidates;
        }

        foreach ($matches[1] as $jsonBlock) {
            $decoded = json_decode(trim(html_entity_decode($jsonBlock, ENT_QUOTES | ENT_HTML5)), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            $primary = [];
            $fallback = [];
            self::collectJsonLdDates($decoded, $primary, $fallback);
            $candidates = [...$candidates, ...$primary, ...$fallback];
        }

        return $candidates;
    }

    private static function collectJsonLdDates(mixed $data, array &$primary, array &$fallback): void
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $normalizedKey = strtolower($key);
                    if (in_array($normalizedKey, ['datepublished', 'datecreated', 'uploaddate'], true) && is_scalar($value)) {
                        $primary[] = (string)$value;
                    } elseif (in_array($normalizedKey, ['datemodified', 'modified', 'dateupdated'], true) && is_scalar($value)) {
                        $fallback[] = (string)$value;
                    }
                }

                self::collectJsonLdDates($value, $primary, $fallback);
            }
        }
    }

    private static function extractTimeDateCandidates(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $candidates = [];
        if (class_exists(\DOMDocument::class)) {
            $dom = new \DOMDocument();
            $previousState = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML($html);
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);

            if ($loaded) {
                foreach ($dom->getElementsByTagName('time') as $timeTag) {
                    $datetime = trim((string)$timeTag->getAttribute('datetime'));
                    if ($datetime !== '') {
                        $candidates[] = $datetime;
                    }
                }
            }
        }

        if (!$candidates && preg_match_all('/<time[^>]+datetime=["\']([^"\']+)["\']/i', $html, $matches)) {
            $candidates = $matches[1];
        }

        return $candidates;
    }

    private static function parsePublishedDate(string $value): ?\DateTimeImmutable
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
        if ($value === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{13}$/', $value)) {
                $date = (new \DateTimeImmutable('@' . (int)floor(((int)$value) / 1000)));
            } elseif (preg_match('/^\d{10}$/', $value)) {
                $date = new \DateTimeImmutable('@' . $value);
            } else {
                $date = new \DateTimeImmutable($value, new \DateTimeZone(date_default_timezone_get()));
            }
        } catch (\Throwable) {
            return null;
        }

        $siteTimeZone = new \DateTimeZone(Craft::$app->getTimeZone());
        $date = $date->setTimezone($siteTimeZone);
        $now = new \DateTimeImmutable('now', $siteTimeZone);

        if ($date > $now->modify('+2 days')) {
            return null;
        }

        return $date;
    }

    private static function shouldSyncPostDate(Entry $entry, bool $isNew): bool
    {
        if ($isNew || $entry->isFieldDirty('externalLink')) {
            return true;
        }

        if (!$entry->postDate) {
            return true;
        }

        return self::timestampsClose($entry->postDate, $entry->dateCreated, 300);
    }

    private static function extractMetaImageUrl(string $html, string $baseUrl): ?string
    {
        if ($html === '') {
            return null;
        }

        $candidates = [];
        if (class_exists(\DOMDocument::class)) {
            $dom = new \DOMDocument();
            $previousState = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML($html);
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);

            if ($loaded) {
                foreach ($dom->getElementsByTagName('meta') as $metaTag) {
                    $property = strtolower(trim((string)$metaTag->getAttribute('property')));
                    $name = strtolower(trim((string)$metaTag->getAttribute('name')));
                    $content = trim((string)$metaTag->getAttribute('content'));

                    if ($content === '') {
                        continue;
                    }

                    if (in_array($property, ['og:image', 'og:image:url', 'og:image:secure_url'], true) || in_array($name, ['twitter:image', 'twitter:image:src'], true)) {
                        $candidates[] = $content;
                    }
                }
            }
        }

        if (!$candidates && preg_match_all('/<(?:meta)[^>]+(?:property|name)=["\'](?:og:image|og:image:url|og:image:secure_url|twitter:image|twitter:image:src)["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $candidates = $matches[1];
        }

        foreach ($candidates as $candidate) {
            $absolute = self::absolutizeUrl($candidate, $baseUrl);
            if ($absolute) {
                return $absolute;
            }
        }

        return null;
    }

    private static function extractContentImageUrl(string $html, string $baseUrl): ?string
    {
        if ($html === '') {
            return null;
        }

        $candidates = [];

        if (class_exists(\DOMDocument::class)) {
            $dom = new \DOMDocument();
            $previousState = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML($html);
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);

            if ($loaded) {
                foreach ($dom->getElementsByTagName('img') as $imgTag) {
                    foreach (['src', 'data-src', 'data-lazy-src'] as $attribute) {
                        $value = trim((string)$imgTag->getAttribute($attribute));
                        if ($value !== '') {
                            $candidates[] = $value;
                            break;
                        }
                    }
                }
            }
        }

        if (!$candidates && preg_match_all('/<img[^>]+(?:src|data-src|data-lazy-src)=["\']([^"\']+)["\']/i', $html, $matches)) {
            $candidates = $matches[1];
        }

        $bestUrl = null;
        $bestScore = -999;

        foreach (array_slice(array_unique($candidates), 0, 20) as $candidate) {
            $absolute = self::absolutizeUrl($candidate, $baseUrl);
            if (!$absolute) {
                continue;
            }

            $score = self::imageCandidateScore($absolute);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestUrl = $absolute;
            }
        }

        return $bestUrl;
    }

    private static function downloadImage(string $imageUrl): ?array
    {
        $response = self::httpGet($imageUrl, 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8');
        if (!$response || ($response['body'] ?? '') === '') {
            return null;
        }

        $body = $response['body'];
        if (strlen($body) > self::MAX_DOWNLOAD_BYTES) {
            Craft::warning(sprintf('Skipping oversized thumbnail download: %s', $imageUrl), __METHOD__);
            return null;
        }

        $mimeType = FileHelper::getMimeTypeByExtension(pathinfo(parse_url($response['effectiveUrl'] ?? $imageUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $tempExtension = pathinfo(parse_url($response['effectiveUrl'] ?? $imageUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION);
        $tempExtension = self::sanitizeExtension($tempExtension);
        $tempFilePath = AssetsHelper::tempFilePath($tempExtension ?: 'tmp');
        file_put_contents($tempFilePath, $body);

        $detectedMimeType = FileHelper::getMimeType($tempFilePath, checkExtension: false) ?: $mimeType ?: ($response['contentType'] ?? null);
        if (!is_string($detectedMimeType) || !str_starts_with(strtolower($detectedMimeType), 'image/')) {
            FileHelper::unlink($tempFilePath);
            return null;
        }

        $extension = self::extensionFromMimeType($detectedMimeType) ?: $tempExtension ?: 'jpg';
        if ($extension !== pathinfo($tempFilePath, PATHINFO_EXTENSION)) {
            $newTempPath = AssetsHelper::tempFilePath($extension);
            rename($tempFilePath, $newTempPath);
            $tempFilePath = $newTempPath;
        }

        return [
            'tempFilePath' => $tempFilePath,
            'mimeType' => $detectedMimeType,
            'extension' => $extension,
        ];
    }

    private static function createAsset(array $download, Entry $entry, string $fieldHandle): ?Asset
    {
        $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
        if (!$field instanceof AssetsField) {
            return null;
        }

        $sourceKey = $field->restrictLocation ? $field->restrictedLocationSource : $field->defaultUploadLocationSource;
        if (!$sourceKey || !str_contains($sourceKey, ':')) {
            return null;
        }

        [, $volumeUid] = explode(':', $sourceKey, 2);
        $volume = Craft::$app->getVolumes()->getVolumeByUid($volumeUid);
        if (!$volume) {
            return null;
        }

        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        if (!$folder) {
            return null;
        }

        $asset = new Asset();
        $asset->tempFilePath = $download['tempFilePath'];
        $asset->setFilename(self::assetFilename($entry, $fieldHandle, $download['extension']));
        $asset->setMimeType($download['mimeType']);
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($folder->volumeId);
        $asset->title = trim($entry->title . ' Thumbnail');
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        $saved = Craft::$app->getElements()->saveElement($asset);
        if (!$saved) {
            Craft::warning(
                sprintf('Unable to save auto-thumbnail asset for entry %s: %s', $entry->id, implode(', ', $asset->getErrorSummary(true))),
                __METHOD__,
            );
            return null;
        }

        return $asset;
    }

    private static function assetFilename(Entry $entry, string $fieldHandle, string $extension): string
    {
        $entryId = $entry->canonicalId ?: $entry->id;
        $token = substr(hash('sha256', $entry->uid . '|' . microtime(true)), 0, 12);

        return sprintf('%s-%s-entry-%s-%s.%s', self::AUTO_FILENAME_PREFIX, $fieldHandle, $entryId, $token, $extension);
    }

    private static function isAutoManagedAsset(Asset $asset, Entry $entry, string $fieldHandle): bool
    {
        $entryId = (string)($entry->canonicalId ?: $entry->id);
        $expectedPrefix = sprintf('%s-%s-entry-%s-', self::AUTO_FILENAME_PREFIX, $fieldHandle, $entryId);

        return str_starts_with($asset->filename, $expectedPrefix);
    }

    private static function httpGet(string $url, string $acceptHeader): ?array
    {
        if (!function_exists('curl_init')) {
            return self::streamHttpGet($url, $acceptHeader);
        }

        $response = self::curlRequest($url, $acceptHeader, true);
        if (!$response && self::lastCurlErrorWasCertificate()) {
            $response = self::curlRequest($url, $acceptHeader, false);
        }

        return $response;
    }

    private static function streamHttpGet(string $url, string $acceptHeader): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: $acceptHeader\r\nAccept-Language: en-US,en;q=0.9\r\nUpgrade-Insecure-Requests: 1\r\nUser-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36\r\n",
                'timeout' => 12,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        $contentType = '';
        foreach ($http_response_header ?? [] as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = trim(substr($header, 13));
                break;
            }
        }

        return [
            'body' => $body,
            'contentType' => trim(strtok($contentType, ';')),
            'effectiveUrl' => $url,
            'headers' => self::normalizeHeaders($http_response_header ?? []),
        ];
    }

    private static ?string $lastCurlError = null;

    private static function curlRequest(string $url, string $acceptHeader, bool $verifySsl): ?array
    {
        self::$lastCurlError = null;

        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: ' . $acceptHeader,
                'Accept-Language: en-US,en;q=0.9',
                'Upgrade-Insecure-Requests: 1',
            ],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function($handle, string $headerLine) use (&$responseHeaders): int {
                $responseHeaders[] = $headerLine;
                return strlen($headerLine);
            },
        ]);

        $body = curl_exec($handle);
        $statusCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($handle);
        self::$lastCurlError = $error ?: null;

        if ($body === false || $statusCode >= 400 || $error) {
            return null;
        }

        return [
            'body' => $body,
            'contentType' => trim(strtok($contentType, ';')),
            'effectiveUrl' => $effectiveUrl ?: $url,
            'headers' => self::normalizeHeaders($responseHeaders),
        ];
    }

    private static function lastCurlErrorWasCertificate(): bool
    {
        if (!self::$lastCurlError) {
            return false;
        }

        return str_contains(strtolower(self::$lastCurlError), 'certificate');
    }

    private static function looksLikeImageUrl(string $url): bool
    {
        return (bool)preg_match('/\.(?:avif|gif|jpe?g|png|svg|webp)(?:[?#].*)?$/i', $url);
    }

    private static function absolutizeUrl(string $candidate, string $baseUrl): ?string
    {
        $candidate = html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5);
        if ($candidate === '' || str_starts_with($candidate, 'data:')) {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $candidate)) {
            return self::normalizeHttpUrl($candidate);
        }

        $base = parse_url($baseUrl);
        if (!$base || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            return self::normalizeHttpUrl($base['scheme'] . ':' . $candidate);
        }

        $path = $candidate;
        if (!str_starts_with($candidate, '/')) {
            $basePath = isset($base['path']) ? preg_replace('#/[^/]*$#', '/', $base['path']) : '/';
            $path = ($basePath ?: '/') . $candidate;
        }

        $normalizedPath = self::normalizePath($path);
        $port = isset($base['port']) ? ':' . $base['port'] : '';

        return self::normalizeHttpUrl(sprintf('%s://%s%s%s', $base['scheme'], $base['host'], $port, $normalizedPath));
    }

    private static function normalizePath(string $path): string
    {
        $pathOnly = $path;
        $query = '';

        if (str_contains($path, '?')) {
            [$pathOnly, $query] = explode('?', $path, 2);
            $query = '?' . $query;
        }

        $segments = explode('/', $pathOnly);
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }

            $normalized[] = $segment;
        }

        return '/' . implode('/', $normalized) . $query;
    }

    private static function sanitizeExtension(?string $extension): ?string
    {
        $extension = strtolower(trim((string)$extension));
        if ($extension === '') {
            return null;
        }

        return preg_replace('/[^a-z0-9]/', '', $extension) ?: null;
    }

    private static function extensionFromMimeType(string $mimeType): ?string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/svg+xml' => 'svg',
            default => null,
        };
    }

    private static function isHtmlContentType(string $contentType): bool
    {
        return str_contains($contentType, 'html') || str_contains($contentType, 'xml');
    }

    private static function normalizeHeaders(array $headerLines): array
    {
        $headers = [];

        foreach ($headerLines as $headerLine) {
            if (!is_string($headerLine) || !str_contains($headerLine, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $headerLine, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if ($name === '' || $value === '') {
                continue;
            }

            $headers[$name][] = $value;
        }

        return $headers;
    }

    private static function extractHeaderDate(array $headers): ?\DateTimeImmutable
    {
        if (!isset($headers['last-modified'])) {
            return null;
        }

        foreach ($headers['last-modified'] as $value) {
            $parsed = self::parsePublishedDate($value);
            if ($parsed) {
                return $parsed;
            }
        }

        return null;
    }

    private static function timestampsClose(?\DateTimeInterface $left, ?\DateTimeInterface $right, int $toleranceSeconds): bool
    {
        if (!$left || !$right) {
            return false;
        }

        return abs($left->getTimestamp() - $right->getTimestamp()) <= $toleranceSeconds;
    }

    private static function imageCandidateScore(string $url): int
    {
        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        $score = 0;

        if (preg_match('/\.(?:jpe?g|png|webp|avif)(?:$|[?#])/i', $url)) {
            $score += 2;
        }

        if (str_contains($path, '/uploads/') || str_contains($path, 'featured') || str_contains($path, 'hero') || str_contains($path, 'banner') || str_contains($path, 'header')) {
            $score += 2;
        }

        if (str_contains($path, 'logo') || str_contains($path, 'icon') || str_contains($path, 'sprite') || str_contains($path, 'dummy') || str_contains($path, 'placeholder')) {
            $score -= 4;
        }

        if (str_ends_with($path, '.svg')) {
            $score -= 3;
        }

        return $score;
    }
}
