<?php

namespace Synergy\Admin\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Builder;
use Intervention\Image\Format;
use Intervention\Image\MediaType;
use Intervention\Image\Interfaces\ImageManagerInterface;

class ImageAdaptationService
{
    public readonly string $defaultMimeType;
    public readonly string $defaultDirectory;
    protected array $config = [];

    public function __construct(
        protected readonly Builder $imageFormatBuilder,
        protected readonly ImageManagerInterface $interventionManager,
    ) {
        $this->defaultMimeType = 'image/webp';
        $this->defaultDirectory = '_generated';
        $imageFormats = $imageFormatBuilder->get();
        foreach ($imageFormats as $imageFormat) {
            $this->config[$imageFormat->key] = $imageFormat->options;
        }
    }

    /**
     * Метод принимает путь до исходного изображения и ключи форматов для обработки согласно конфигурации
     *
     * @param  string $imagePath
     * @param  array $keys
     * @param  bool $forceCreate
     * @return array
     */
    public function generateByKeys(string $imagePath, array $keys, bool $forceCreate = false): array
    {
        $generateResult = [];
        if (!Storage::exists($imagePath)) {
            Log::error('Изображение не найдено: ' . $imagePath);
            return $generateResult;
        }
        $interventionImageOrig = $this->interventionManager->read(Storage::get($imagePath));
        foreach ($keys as $key) {
            if (empty($this->config[$key])) {
                Log::warning('Отсутствует конфигурация обработки изображения для ключа ' . $key);
                continue;
            }

            $mimeType = $this->config[$key]['type'] ?? $this->defaultMimeType;
            if (!$this->checkMimeType($mimeType)) {
                Log::warning('Неподдерживаемый mime type для ключа ' . $key);
                continue;
            }

            $encodedImagePath = $this->getEncodedImagePath($imagePath, $key, $mimeType);
            if (Storage::exists($encodedImagePath) && !$forceCreate) {
                $generateResult[$key] = Storage::url($encodedImagePath);
                continue;
            }

            $interventionImage = clone $interventionImageOrig;
            $crop = isset($this->config[$key]['crop']) ? (bool) $this->config[$key]['crop'] : false;
            $width = !empty($this->config[$key]['width']) ? $this->config[$key]['width'] : $interventionImage->width();
            $height = !empty($this->config[$key]['height']) ? $this->config[$key]['height'] : $interventionImage->height();

            if ($crop) {
                $interventionImage->coverDown($width, $height, 'center');
            } else {
                $interventionImage->scaleDown($width, $height);
            }

            $encodedInterventionImage = $interventionImage->encodeByMediaType($mimeType);
            if (
                Storage::put(
                    $encodedImagePath,
                    $encodedInterventionImage,
                    ['visibility' => 'public', 'directory_visibility' => 'public']
                )
            ) {
                $generateResult[$key] = Storage::url($encodedImagePath);
            }
        }
        return $generateResult;
    }

    /**
     * Удаляет все обработанные изображения для указанного оригинального изображения
     *
     * @param  string $imagePath
     * @return void
     */
    public function delete(string $imagePath): void
    {
        $imagePath = '/' . ltrim($imagePath, '/');
        $pathInfo = pathinfo($imagePath);
        $directory = $pathInfo['dirname'] . '/' . $this->defaultDirectory . '/' . $pathInfo['basename'];
        if (Storage::exists($directory)) {
            $files = Storage::files($directory);
            Storage::delete($files);
            Storage::deleteDirectory($directory);
        }
    }

    /**
     * Проверяет, если переданный тип среди поддерживаемых Intervention Image
     *
     * @param  string $mimeType
     * @return bool
     */
    public function checkMimeType(string $mimeType): bool
    {
        return (bool) MediaType::tryFrom($mimeType);
    }

    /**
     * Возвращает путь для будущего обработанного файла
     * согласно имени оригинального изображения, ключа и типа изображения
     *
     * @param  string $imagePath
     * @param  string $key
     * @param  string $mimeType
     * @return string
     */
    public function getEncodedImagePath(string $imagePath, string $key, string $mimeType): string
    {
        $imagePath = '/' . ltrim($imagePath, '/');
        $pathInfo = pathinfo($imagePath);
        $encodedDirectory = $pathInfo['dirname'] . '/' . $this->defaultDirectory . '/' . $pathInfo['basename'];
        return ltrim($encodedDirectory, '/') . '/' . $key . '.' . $this->getExtensionByMime($mimeType);
    }

    /**
     * Возвращает расширение файла согласно типу
     *
     * @param  string $mimeType
     * @return string
     */
    public function getExtensionByMime(string $mimeType): string
    {
        $extensions = Format::create($mimeType)->fileExtensions();
        return array_shift($extensions)->value;
    }
}
