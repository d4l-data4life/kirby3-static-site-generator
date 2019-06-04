<?php
namespace D4L;

use Closure;
use Dir;
use Error;
use F;
use Kirby\Cms\App;
use Kirby\Cms\Ingredients;
use Kirby\Cms\Page;


class StaticSiteGenerator
{
  protected $_kirby;
  protected $_pathsToCopy;
  protected $_outputFolder;

  protected $_originalUrls = [];

  protected $_pages;
  protected $_fileList = [];

  protected $_defaultLanguage;
  protected $_languages;

  public function __construct(App $kirby, array $pathsToCopy = null)
  {
    $this->_kirby = $kirby;

    $this->_pathsToCopy = $pathsToCopy ?: [$kirby->roots()->assets()];
    $this->_pathsToCopy = $this->_resolveRelativePaths($this->_pathsToCopy);
    $this->_outputFolder = $this->_resolveRelativePath('./static');

    $this->_originalUrls = $kirby->urls()->toArray();
    $this->_pages = $kirby->site()->index();

    $this->_defaultLanguage = $kirby->languages()->default();
    $this->_languages = $this->_defaultLanguage ? $kirby->languages()->keys() : [$this->_defaultLanguage];
  }

  public function generate(string $outputFolder = './static', string $baseUrl = '/', array $preserve = [])
  {
    $this->_outputFolder = $this->_resolveRelativePath($outputFolder ?: $this->_outputFolder);
    if (!$this->_outputFolder || $this->_outputFolder === $this->_kirby->roots()->index()) {
      throw new Error('Please specify a valid output folder!');
    }

    $this->clearFolder($this->_outputFolder, $preserve);
    $this->generatePages($baseUrl);
    foreach ($this->_pathsToCopy as $pathToCopy) {
      $this->copyFiles($pathToCopy);
    }

    return $this->_fileList;
  }

  public function generatePages(string $baseUrl = '/')
  {
    $baseUrl = rtrim($baseUrl, '/') ?: '/';
    $this->_modifyUrls($baseUrl);
    StaticSiteGeneratorMedia::setActive(true);

    $homePage = $this->_pages->findBy('isHomePage', 'true');
    $this->_setPageLanguage($homePage, $this->_defaultLanguage);
    $this->_generatePage($homePage, $this->_outputFolder . DS . 'index.html');

    foreach ($this->_languages as $languageCode) {
      $this->_generatePagesByLanguage($baseUrl, $languageCode);
    }

    $this->_copyMediaFiles($baseUrl);

    StaticSiteGeneratorMedia::setActive(false);
    StaticSiteGeneratorMedia::clearList();
    $this->_restoreUrls();

    return $this->_fileList;
  }

  protected function _generatePagesByLanguage(string $baseUrl, string $languageCode = null)
  {
    foreach ($this->_pages->keys() as $key) {
      $page = $this->_pages->$key;
      $this->_setPageLanguage($page, $languageCode);
      $path = str_replace($baseUrl, '/', $page->url());
      $path = str_replace('//', '/', $path);
      $path = $this->_outputFolder . str_replace('/', DS, $path) . DS . 'index.html';
      $this->_generatePage($page, $path);
    }
  }

  protected function _setPageLanguage(Page $page, string $languageCode = null)
  {
    foreach ($this->_pages as $page) {
      $page->content = null;
    }

    $kirby = $this->_kirby;
    $kirby->cache('pages')->flush();
    $kirby->site()->content = null;
    $kirby->site()->visit($page, $languageCode);
  }

  protected function _generatePage(Page $page, string $path)
  {
    $html = $page->render();
    F::write($path, $html);

    $this->_fileList = array_unique(array_merge($this->_fileList, [$path]));
  }

  public function copyFiles(string $folder = null)
  {
    $outputFolder = $this->_outputFolder;

    if (!$folder || !file_exists($folder)) {
      return $this->_fileList;
    }

    $folderName = $this->_getFolderName($folder);
    $targetPath = $outputFolder . DS . $folderName;

    if (is_file($folder)) {
      return $this->_copyFile($folder, $targetPath);
    }

    $this->clearFolder($targetPath);
    if (!Dir::copy($folder, $targetPath)) {
      return $this->_fileList;
    }

    $list = $this->_getFileList($targetPath, true);
    $this->_fileList = array_unique(array_merge($this->_fileList, $list));
    return $this->_fileList;
  }

  protected function _copyMediaFiles(string $baseUrl = '/')
  {
    $outputFolder = $this->_outputFolder;
    $mediaList = StaticSiteGeneratorMedia::getList();

    foreach ($mediaList as $item) {
      $file = $item['root'];
      $path = str_replace($baseUrl, '/', $item['url']);
      $path = str_replace('//', '/', $path);
      $path =  $outputFolder . str_replace('/', DS, $path);
      $this->_copyFile($file, $path);
    }

    $this->_fileList = array_unique($this->_fileList);
    return $this->_fileList;
  }

  protected function _copyFile($file, $targetPath)
  {
    if (F::copy($file, $targetPath)) {
      $this->_fileList[] = $targetPath;
    }

    return $this->_fileList;
  }

  public function clearFolder(string $folder, array $preserve = [])
  {
    $folder = $this->_resolveRelativePath($folder);
    if (!count($preserve)) {
      return Dir::remove($folder);
    }

    $items = $this->_getFileList($folder);
    return array_reduce($items, function ($totalResult, $item) use ($preserve) {
      if (in_array($this->_getFolderName($item), $preserve)) {
        return $totalResult;
      }

      $result = is_dir($item) === false ? F::remove($item) : Dir::remove($item);
      return $totalResult && $result;
    }, true);
  }

  protected function _getFolderName(string $folder)
  {
    $segments = explode(DS, $folder);
    return array_pop($segments);
  }

  protected function _getFileList(string $path, bool $recursively = false)
  {
    $items = Dir::read($path, [], true);
    if (!$recursively) {
      return $items;
    }

    return array_reduce($items, function ($list, $item) {
      if (is_dir($item)) {
        return array_merge($list, $this->_getFileList($item, true));
      }

      return array_merge($list, [$item]);
    }, []) ?: [];
  }

  protected function _modifyUrls(string $baseUrl)
  {
    $originalBaseUrl = $this->_kirby->urls()->base();
    $mediaUrl = $baseUrl . str_replace($originalBaseUrl, '', $this->_kirby->urls()->media());
    $mediaUrl = str_replace('//', '/', $mediaUrl);

    $this->_modifyUrl('index', $baseUrl);
    $this->_modifyUrl('base', $baseUrl);
    $this->_modifyUrl('media', $mediaUrl);

    $urlKeys = array_keys($this->_originalUrls);
    foreach ($this->_kirby->roots()->toArray() as $key => $root) {
      if (in_array($root, $this->_pathsToCopy) && in_array($key, $urlKeys)) {
        $folderName = $this->_getFolderName($root);
        $this->_modifyUrl($key, $baseUrl . '/' . $folderName);
      }
    }
  }

  protected function _modifyUrl(string $property, string $url)
  {
    $url = str_replace('//', '/', $url);
    $setUrl = $this->_getUrlSetter();
    $setUrl($this->_kirby, $property, $url);
  }

  protected function _restoreUrls()
  {
    $urls = $this->_kirby->urls();
    foreach ($this->_originalUrls as $key => $originalUrl) {
      if ($originalUrl !== $urls->$key()) {
        $this->_restoreUrl($key);
      }
    }
  }

  protected function _restoreUrl(string $property)
  {
    if (isset($this->_originalUrls[$property])) {
      $setUrl = $this->_getUrlSetter();
      $setUrl($this->_kirby, $property, $this->_originalUrls[$property]);
    }
  }

  protected function _getUrlSetter()
  {
    return Closure::bind(function (App $kirby, string $property, string $value) {
      $urls = $kirby->urls->toArray();
      $urls[$property] = $value;
      return $kirby->urls = new Ingredients($urls);
    }, null, 'Kirby\Cms\App');
  }

  protected function _resolveRelativePaths(array $paths)
  {
    return array_values(array_filter(array_map(function ($path) {
      return $this->_resolveRelativePath($path);
    }, $paths)));
  }

  protected function _resolveRelativePath(string $path = null)
  {
    if (!$path || strpos($path, '.') !== 0) {
      return realpath($path) ?: $path;
    }

    $path = $this->_kirby->roots()->index() . DS . $path;
    return realpath($path) ?: $path;
  }
}

