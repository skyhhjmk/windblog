<?php

use Phinx\Migration\AbstractMigration;

class AddPerformanceIndexes extends AbstractMigration
{
    public function change()
    {
        // Posts table: Composite index on status and published_at for efficient filtering/sorting
        $posts = $this->table('posts');
        if (!$posts->hasIndex(['status', 'published_at'])) {
            $posts->addIndex(['status', 'published_at'])
                ->save();
        }

        // Tags table: Unique index on slug for faster lookups
        $tags = $this->table('tags');
        if (!$tags->hasIndex(['slug'])) {
            $tags->addIndex(['slug'], ['unique' => true])
                ->save();
        }

        // Categories table: Unique index on slug for faster lookups
        $categories = $this->table('categories');
        if (!$categories->hasIndex(['slug'])) {
            $categories->addIndex(['slug'], ['unique' => true])
                ->save();
        }

        // Comments table: Index on post_id for faster retrieval of comments by post
        $comments = $this->table('comments');
        if (!$comments->hasIndex(['post_id'])) {
            $comments->addIndex(['post_id'])
                ->save();
        }

        // Links table: GIN index on custom_fields (PostgreSQL only)
        // This allows for efficient querying of JSON data
        $adapter = $this->getAdapter();
        $adapterType = $adapter->getAdapterType();

        if ($adapterType === 'pgsql') {
            // Check if index exists using raw SQL query to avoid error if it does
            $hasIndex = $this->fetchRow("SELECT 1 FROM pg_indexes WHERE indexname = 'idx_links_custom_fields'");
            if (!$hasIndex) {
                $this->execute('CREATE INDEX idx_links_custom_fields ON links USING GIN (custom_fields)');
            }
        }
    }
}
