<?php

namespace Backpack\Basset\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Basset Clear command.
 *
 * @property object $output
 */
class BassetClear extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'basset:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the basset cache';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        /** @var FilesystemAdapter */
        $disk = Storage::disk(config('backpack.basset.disk'));
        $cacheDisk = Storage::disk(config('backpack.basset.cache_map_disk'));
        $path = config('backpack.basset.path');
        $cachePath = config('backpack.basset.cache_path');
        $pathRelative = Str::of($disk->path($path))->replace(base_path(), '')->replace('\\', '/');

        $this->line("Clearing basset '$pathRelative'");

        $disk->deleteDirectory($path);
        $disk->makeDirectory($path);
        $cacheDisk->deleteDirectory($cachePath);
        $cacheDisk->makeDirectory($cachePath);

        $this->info('Done');
    }
}
