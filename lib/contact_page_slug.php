<?php
declare(strict_types=1);

const CONTACT_PAGE_OLD_SLUG = 'contact';
const CONTACT_PAGE_SLUG = 'que';

function migrate_contact_page_slug(): void
{
    if (!db_table_exists('fixed_pages')) {
        return;
    }

    $stmt = db()->prepare('SELECT id FROM fixed_pages WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => CONTACT_PAGE_OLD_SLUG]);
    $contactId = (int)($stmt->fetchColumn() ?: 0);
    if ($contactId <= 0) {
        return;
    }

    $stmt = db()->prepare('SELECT id FROM fixed_pages WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => CONTACT_PAGE_SLUG]);
    $queId = (int)($stmt->fetchColumn() ?: 0);
    if ($queId > 0) {
        error_log('contact_page_slug_migration skipped: slug "que" already exists; contact page id=' . $contactId . ' was not changed');
        return;
    }

    db()->prepare('UPDATE fixed_pages SET slug = :new_slug, updated_at = NOW() WHERE id = :id AND slug = :old_slug')->execute([
        ':new_slug' => CONTACT_PAGE_SLUG,
        ':old_slug' => CONTACT_PAGE_OLD_SLUG,
        ':id' => $contactId,
    ]);
}
