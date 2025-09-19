<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class TestPosts extends AbstractSeed
{
    private $faker;
    /**
     * Run Method.
     *
     * Write your database seeder using this method.
     *
     * More information on writing seeders is available here:
     * https://book.cakephp.org/phinx/0/en/seeding.html
     */
    public function run(): void
    {
        $faker = new Xefi\Faker\Faker();
        $data = [
            [
                'title'    => $faker->words(words: 3),
                'slug' => $faker->words(words: 1),
                'content'    => $faker->paragraphs(paragraphs: 3),
                'created' => date('Y-m-d H:i:s'),
                'updated' => date('Y-m-d H:i:s'),
            ],
            [
                'body'    => 'bar',
                'created' => date('Y-m-d H:i:s'),
            ]
        ];

        $posts = $this->table('posts');
        $posts->insert($data)
            ->save();
    }
}
