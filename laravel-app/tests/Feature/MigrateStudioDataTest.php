<?php

namespace Tests\Feature;

use App\Support\StudioPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigrateStudioDataTest extends TestCase
{
    private string $dataDir;

    private string $appRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $prefix = sys_get_temp_dir().'/migrate-studio-data-'.uniqid();
        $this->dataDir = $prefix.'/data';
        $this->appRoot = $prefix.'/app';

        config(['studio.data_dir' => $this->dataDir]);
        File::ensureDirectoryExists($this->appRoot);
    }

    public function test_moves_legacy_database_and_media_into_the_data_root(): void
    {
        File::ensureDirectoryExists($this->appRoot.'/database');
        File::ensureDirectoryExists($this->appRoot.'/storage/app/public/reels');
        File::ensureDirectoryExists($this->appRoot.'/storage/app/input_videos');
        File::put($this->appRoot.'/database/database.sqlite', 'legacy-db');
        File::put($this->appRoot.'/storage/app/public/reels/reel_1.mp4', 'legacy-reel');
        File::put($this->appRoot.'/storage/app/input_videos/clip.mp4', 'legacy-clip');

        $this->artisan('studio:migrate-data', ['--app-root' => $this->appRoot])->assertSuccessful();

        $this->assertSame('legacy-db', File::get(StudioPaths::database()));
        $this->assertSame('legacy-reel', File::get(StudioPaths::videos().'/reels/reel_1.mp4'));
        $this->assertSame('legacy-clip', File::get(StudioPaths::uploads().'/input_videos/clip.mp4'));
        $this->assertFileExists(StudioPaths::root().'/.data-migrated');
    }

    public function test_is_idempotent_and_never_overwrites_existing_data(): void
    {
        File::ensureDirectoryExists($this->appRoot.'/database');
        File::ensureDirectoryExists($this->appRoot.'/storage/app/public/reels');
        File::ensureDirectoryExists(StudioPaths::root().'/database');
        File::ensureDirectoryExists(StudioPaths::root().'/videos/reels');
        File::put($this->appRoot.'/database/database.sqlite', 'legacy-db');
        File::put($this->appRoot.'/storage/app/public/reels/reel_1.mp4', 'legacy-reel');
        File::put(StudioPaths::database(), 'existing-db');
        File::put(StudioPaths::videos().'/reels/reel_2.mp4', 'existing-reel');

        $this->artisan('studio:migrate-data', ['--app-root' => $this->appRoot])->assertSuccessful();

        $this->assertSame('existing-db', File::get(StudioPaths::database()));
        $this->assertSame('existing-reel', File::get(StudioPaths::videos().'/reels/reel_2.mp4'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->dataDir));

        parent::tearDown();
    }
}
