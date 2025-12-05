<?php

namespace app\util;

use Illuminate\Database\Eloquent\Collection;

/**
 * RelationLoader provides standardized eager loading for models to prevent N+1 query issues.
 */
class RelationLoader
{
    /**
     * Load standard relations for a collection of posts.
     *
     * @param Collection $posts
     *
     * @return Collection
     */
    public static function loadPostRelations(Collection $posts): Collection
    {
        if ($posts->isEmpty()) {
            return $posts;
        }

        return $posts->load([
            'authors',
            'primaryAuthor',
            'categories',
            'tags',
            'featuredImage', // Assuming this relation exists or is needed
        ]);
    }

    /**
     * Load standard relations for a collection of comments.
     *
     * @param Collection $comments
     *
     * @return Collection
     */
    public static function loadCommentRelations(Collection $comments): Collection
    {
        if ($comments->isEmpty()) {
            return $comments;
        }

        return $comments->load([
            'post',
            'user',
            'parent',
        ]);
    }
}
