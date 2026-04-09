<?php

declare(strict_types=1);

namespace craft\contentmigrations;

use Craft;
use DateTime;
use craft\db\Migration;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\ElementHelper;

class m260325_220000_seed_default_resources_entries extends Migration
{
    public function safeUp(): bool
    {
        $section = Craft::$app->entries->getSectionByHandle('resources');
        if (!$section) {
            echo "Resources section not found. Skipping seed.\n";
            return true;
        }

        $entryType = $section->getEntryTypes()[0] ?? null;
        if (!$entryType) {
            echo "No entry type found for Resources. Skipping seed.\n";
            return true;
        }

        $site = Craft::$app->getSites()->getPrimarySite();
        if (!$site) {
            echo "Primary site not found. Skipping seed.\n";
            return true;
        }

        $targetCount = 12;
        $existingCount = (int)Entry::find()
            ->section('resources')
            ->siteId($site->id)
            ->status(null)
            ->count();

        if ($existingCount >= $targetCount) {
            echo "Resources already has {$existingCount} entries. No seed needed.\n";
            return true;
        }

        $author = User::find()->status(null)->orderBy('id asc')->one();
        $authorId = $author?->id;

        $seedRows = [
            ['Colorado Alcohol Retail Density Study', 'Policy impacts of retail availability across urban and rural communities.', 'policy-brief', 'https://example.com/resources/retail-density-study'],
            ['Prevention Toolkit for Local Coalitions', 'Actionable guidance and tools for coalition leaders and partners.', 'community-guide', 'https://example.com/resources/prevention-toolkit'],
            ['2023 Annual Impact Report', 'Year-end review of initiatives, progress, and outcomes across Colorado.', 'data-report', 'https://example.com/resources/annual-impact-report'],
            ['Urban Zoning Reform Impacts', 'Case analysis of zoning changes and neighborhood-level prevention outcomes.', 'case-study', 'https://example.com/resources/zoning-reform'],
            ['Youth Engagement Quick Facts', 'Snapshot metrics on youth-centered interventions and participation trends.', 'data-report', 'https://example.com/resources/youth-quick-facts'],
            ['Future Trends in Health Policy', 'Forward-looking policy brief for prevention planning and advocacy.', 'whitepaper', 'https://example.com/resources/future-trends-health-policy'],
            ['Community Data Dashboard Guide', 'How to interpret local indicators and use dashboards for decision-making.', 'community-guide', 'https://example.com/resources/dashboard-guide'],
            ['Coalition Messaging Framework', 'Shared messaging templates for partners, media, and public outreach.', 'policy-brief', 'https://example.com/resources/messaging-framework'],
            ['Rural Access Equity Brief', 'Barriers and opportunities for equitable prevention access in rural regions.', 'policy-brief', 'https://example.com/resources/rural-access-equity'],
            ['Behavioral Health Systems Map', 'Reference map of services, referral pathways, and key support contacts.', 'data-report', 'https://example.com/resources/systems-map'],
            ['Campus Prevention Case Study', 'Implementation notes from campus-based prevention programming.', 'case-study', 'https://example.com/resources/campus-case-study'],
            ['Statewide Resource Index', 'Central index of partner resources, guides, and evidence summaries.', 'news', 'https://example.com/resources/statewide-resource-index'],
        ];

        for ($index = $existingCount; $index < $targetCount; $index++) {
            $row = $seedRows[$index];
            [$title, $summary, $tag, $url] = $row;

            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entry->typeId = $entryType->id;
            $entry->siteId = $site->id;
            $entry->enabled = true;
            $entry->setEnabledForSite(true);
            $entry->title = $title;
            $entry->slug = ElementHelper::generateSlug($title . '-' . ($index + 1));
            $entry->postDate = new DateTime(sprintf('-%d days', $index + 1));

            if ($authorId) {
                $entry->authorId = $authorId;
            }

            $entry->setFieldValue('summary', $summary);
            $entry->setFieldValue('categoryLabel', $tag);
            $entry->setFieldValue('externalLink', $url);

            if (!Craft::$app->getElements()->saveElement($entry, false)) {
                $errors = json_encode($entry->getErrors(), JSON_UNESCAPED_SLASHES);
                throw new \RuntimeException("Could not create seeded resource entry: {$title}. Errors: {$errors}");
            }
        }

        $created = $targetCount - $existingCount;
        echo "Created {$created} default resource entries.\n";
        return true;
    }

    public function safeDown(): bool
    {
        echo "Seed migration does not remove content.\n";
        return true;
    }
}
