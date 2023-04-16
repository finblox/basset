<?php

namespace Backpack\Basset;

use Backpack\Basset\Enums\StatusEnum;
use Backpack\Basset\Helpers\CacheMap;
use Backpack\Basset\Helpers\LoadingTime;
use Backpack\Basset\Helpers\Unarchiver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Basset Manager.
 */
class BassetManager
{
    use Traits\ViewPathsTrait;

    private $loaded;
    private $disk;
    private $basePath;
    private $cachebusting;

    public $cacheMap;
    public $loading;
    public $unarchiver;

    public function __construct()
    {
        $this->loaded = [];
        $this->disk = Storage::disk(config('backpack.basset.disk'));

        $cachebusting = config('backpack.basset.cachebusting');
        $this->cachebusting = $cachebusting ? (string) Str::of($cachebusting)->start('?') : '';
        $this->basePath = (string) Str::of(config('backpack.basset.path'))->finish('/');

        $this->cacheMap = new CacheMap();
        $this->loading = new LoadingTime();
        $this->unarchiver = new Unarchiver();

        // initialize static view path methods
        $this->initViewPaths();
    }

    /**
     * Adds the basset to the current loaded basset list.
     *
     * @param  string  $asset
     * @return void
     */
    public function markAsLoaded(string $asset): void
    {
        if (! $this->isLoaded($asset)) {
            $this->loaded[] = $asset;
        }
    }

    /**
     * Checks if the asset is already on loaded asset list.
     *
     * @param  string  $asset
     * @return bool
     */
    public function isLoaded(string $asset): bool
    {
        return in_array($asset, $this->loaded);
    }

    /**
     * Returns the current loaded basset list on app lifecycle.
     *
     * @return array
     */
    public function loaded(): array
    {
        return $this->loaded;
    }

    /**
     * Outputs a file depending on its type.
     *
     * @param  string  $path
     * @param  array  $attributes
     * @return void
     */
    public function echoFile(string $path, array $attributes = []): void
    {
        if (substr($path, -3) === '.js') {
            $this->echoJs($path, $attributes);
        }

        if (substr($path, -4) === '.css') {
            $this->echoCss($path, $attributes);
        }
    }

    /**
     * Outputs the CSS link tag.
     *
     * @param  string  $path
     * @param  array  $attributes
     * @return void
     */
    public function echoCss(string $path, array $attributes = []): void
    {
        $args = '';
        foreach ($attributes as $key => $value) {
            $args .= " $key".($value === true || empty($value) ? '' : "=\"$value\"");
        }

        echo '<link href="'.asset($path.$this->cachebusting).'"'.$args.' rel="stylesheet" type="text/css" />'.PHP_EOL;
    }

    /**
     * Outputs the JS script tag.
     *
     * @param  string  $path
     * @return void
     */
    public function echoJs(string $path, array $attributes = []): void
    {
        $args = '';
        foreach ($attributes as $key => $value) {
            $args .= " $key".($value === true || empty($value) ? '' : "=\"$value\"");
        }

        echo '<script src="'.asset($path.$this->cachebusting).'"'.$args.'></script>'.PHP_EOL;
    }

    /**
     * Returns the asset path.
     *
     * @param  string  $asset
     * @return string
     */
    public function getPath(string $asset): string
    {
        return Str::of($this->basePath)
            ->append(str_replace([base_path(), 'http://', 'https://', '://', '<', '>', ':', '"', '|', '?', "\0", '*', '`', ';', "'", '+'], '', $asset))
            ->replace('/\\', '/');
    }

    /**
     * Returns the asset url.
     *
     * @param  string  $asset
     * @return string
     */
    public function getUrl(string $asset): string
    {
        return $this->disk->url($this->getPath($asset));
    }

    /**
     * Internalize a CDN or local asset.
     *
     * @param  string  $asset
     * @param  mixed  $output
     * @param  array  $attributes
     * @return StatusEnum
     */
    public function basset(string $asset, bool|string $output = true, array $attributes = []): StatusEnum
    {
        $this->loading->start();

        // Get asset path
        $path = $this->getPath(is_string($output) ? $output : $asset);

        if ($this->isLoaded($path)) {
            return $this->loading->finish(StatusEnum::LOADED);
        }

        $this->markAsLoaded($path);

        // Retrieve from map
        $mapped = $this->cacheMap->get($asset);
        if ($mapped) {
            $output && $this->echoFile($mapped, $attributes);

            return $this->loading->finish(StatusEnum::IN_CACHE);
        }

        // Validate the asset is an absolute path or a CDN
        if (! str_starts_with($asset, base_path()) && ! str_starts_with($asset, 'http') && ! str_starts_with($asset, '://')) {
            // may be an internalized asset (folder or zip)
            if ($this->disk->exists($path)) {
                $asset = $this->disk->url($path);
                $output && $this->echoFile($asset, $attributes);

                return $this->loading->finish(StatusEnum::IN_CACHE);
            }

            // public file (default fallback)
            $output && $this->echoFile($asset, $attributes);

            return $this->loading->finish(StatusEnum::INVALID);
        }

        // Get asset url
        $url = $this->disk->url($path);

        // Check if asset exists in basset folder
        if ($this->disk->exists($path)) {
            $output && $this->echoFile($url, $attributes);
            $this->cacheMap->add($asset, $url);

            return $this->loading->finish(StatusEnum::IN_CACHE);
        }

        // Download/copy file
        if (str_starts_with($asset, 'http') || str_starts_with($asset, '://')) {
            $content = Http::get($asset)->getBody();
        } else {
            $content = File::get($asset);
        }

        // Clean source map
        $content = preg_replace('/sourceMappingURL=/', '', $content);

        $result = $this->disk->put($path, $content);

        if ($result) {
            $output && $this->echoFile($url, $attributes);
            $this->cacheMap->add($asset, $url);

            return $this->loading->finish(StatusEnum::INTERNALIZED);
        }

        // Fallback to the CDN/path
        $output && $this->echoFile($asset, $attributes);

        return $this->loading->finish(StatusEnum::INVALID);
    }

    /**
     * Internalize a basset code block.
     *
     * @param  string  $asset
     * @param  string  $code
     * @return StatusEnum
     */
    public function bassetBlock(string $asset, string $code, bool $output = true): StatusEnum
    {
        $this->loading->start();

        // Get asset path and url
        $path = $this->getPath($asset);

        if ($this->isLoaded($path)) {
            return $this->loading->finish(StatusEnum::LOADED);
        }

        $this->markAsLoaded($path);

        // Retrieve from map
        $mapped = $this->cacheMap->get($asset);
        if ($mapped) {
            $output && $this->echoFile($mapped);

            return $this->loading->finish(StatusEnum::IN_CACHE);
        }

        // Get asset url
        $url = $this->disk->url($path);

        // Check if asset exists in basset folder
        if ($this->disk->exists($path)) {
            $output && $this->echoFile($url);
            $this->cacheMap->add($asset, $url);

            return $this->loading->finish(StatusEnum::IN_CACHE);
        }

        // Strip tags
        $cleanCode = preg_replace('/^\s*\<\/?(script|style).*?\/?\s*?\>/ms', '', $code);

        // Clear empty lines
        $cleanCode = preg_replace('/^(?:[\t ]*(?:\r?\n|\r))+/', '', $cleanCode);

        // clean the left padding
        preg_match("/^\s*/", $cleanCode, $matches);
        $cleanCode = preg_replace('/^'.($matches[0] ?? '').'/m', '', $cleanCode);

        // Store the file
        $result = $this->disk->put($path, $cleanCode);

        if ($result) {
            $output && $this->echoFile($url);
            $this->cacheMap->add($asset, $url);

            return $this->loading->finish(StatusEnum::INTERNALIZED);
        }

        // Fallback to the code
        echo $code;

        return $this->loading->finish(StatusEnum::INVALID);
    }

    /**
     * Internalize an Archive.
     *
     * @param  string  $asset
     * @param  string|null  $output
     * @return StatusEnum
     */
    public function bassetArchive(string $asset, string $output): StatusEnum
    {
        $this->loading->start();

        // get local output path
        $path = $this->getPath($output);
        $output = $this->disk->path($path);

        // Check if asset is loaded
        if ($this->isLoaded($path)) {
            return $this->loading->finish(StatusEnum::LOADED);
        }

        $this->markAsLoaded($path);

        // Retrieve from map
        if ($this->cacheMap->get($asset)) {
            return $this->loading->finish(StatusEnum::IN_CACHE);
        }

        // check if directory exists
        if ($this->disk->exists($path)) {
            $this->cacheMap->add($asset);

            return $this->loading->finish(StatusEnum::IN_CACHE);
        }

        // local zip file
        if (File::isFile($asset)) {
            $file = $asset;
        }

        // online zip
        if (str_starts_with($asset, 'http') || str_starts_with($asset, '://')) {
            // temporary file
            $file = $this->unarchiver->getTemporaryFilePath();

            // download file to temporary location
            $content = Http::get($asset)->getBody();
            File::put($file, $content);
        }

        if (! isset($file)) {
            return $this->loading->finish(StatusEnum::INVALID);
        }

        $tempDir = $this->unarchiver->getTemporaryDirectoryPath();
        $this->unarchiver->unarchiveFile($file, $tempDir);

        // internalize all files in the folder
        foreach (File::allFiles($tempDir) as $file) {
            $this->disk->put("$path/{$file->getRelativePathName()}", File::get($file));
        }

        File::delete($tempDir);
        $this->cacheMap->add($asset);

        return $this->loading->finish(StatusEnum::INTERNALIZED);
    }

    /**
     * Internalize a Directory.
     *
     * @param  string  $asset
     * @param  string|null  $output
     * @return StatusEnum
     */
    public function bassetDirectory(string $asset, string $output): StatusEnum
    {
        $this->loading->start();

        // get local output path
        $path = $this->getPath($output);

        // Check if asset is loaded
        if ($this->isLoaded($path)) {
            return $this->loading->finish(StatusEnum::LOADED);
        }

        $this->markAsLoaded($path);

        // Retrieve from map
        if ($this->cacheMap->get($asset)) {
            return $this->loading->finish(StatusEnum::IN_CACHE);
        }

        // check if directory exists
        if ($this->disk->exists($path)) {
            $this->cacheMap->add($asset);

            return $this->loading->finish(StatusEnum::IN_CACHE);
        }

        // check if folder exists in filesystem
        if (! File::exists($asset)) {
            return $this->loading->finish(StatusEnum::INVALID);
        }

        // internalize all files in the folder
        foreach (File::allFiles($asset) as $file) {
            $this->disk->put("$path/{$file->getRelativePathName()}", File::get($file));
        }

        $this->cacheMap->add($asset);

        return $this->loading->finish(StatusEnum::INTERNALIZED);
    }
}
