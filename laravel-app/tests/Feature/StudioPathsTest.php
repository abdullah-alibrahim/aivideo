<?php

namespace Tests\Feature;

use App\Support\StudioPaths;
use Tests\TestCase;

class StudioPathsTest extends TestCase
{
    public function test_explicit_data_dir_override_wins(): void
    {
        config(['studio.data_dir' => '/tmp/custom-studio-data']);

        $this->assertSame('/tmp/custom-studio-data', StudioPaths::root());
        $this->assertSame('/tmp/custom-studio-data/videos', StudioPaths::videos());
        $this->assertSame('/tmp/custom-studio-data/database/database.sqlite', StudioPaths::database());
    }

    public function test_macos_default_points_to_application_support(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('macOS-specific default');
        }

        $expected = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME');
        $this->assertSame(
            $expected.'/Library/Application Support/AI Reels Studio',
            StudioPaths::root(),
        );
    }

    public function test_ensure_creates_every_subdirectory(): void
    {
        config(['studio.data_dir' => sys_get_temp_dir().'/studio-paths-test-'.uniqid()]);

        StudioPaths::ensure();

        foreach (['database', 'videos', 'uploads', 'backups', 'settings', 'keys', 'logs', 'tmp', 'tmp/progress'] as $sub) {
            $this->assertDirectoryExists(StudioPaths::root().'/'.$sub);
        }

        $this->assertDirectoryExists(StudioPaths::backgrounds());
    }

    protected function tearDown(): void
    {
        $root = StudioPaths::root();

        if (str_starts_with($root, sys_get_temp_dir()) && str_contains($root, 'studio-paths-test-')) {
            @rmdir($root.'/database');
            @rmdir($root);
        }

        parent::tearDown();
    }
}
