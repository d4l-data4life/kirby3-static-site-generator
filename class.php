<?php

namespace D4L;

use Error;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Dir;
use Kirby\Toolkit\F;
use Whoops\Exception\ErrorException;

class StaticSiteGenerator
{
  protected $_kirby;
  protected $_pathsToCopy;
  protected $_outputFolder;

  protected $_pages;
  protected $_fileList = [];

  protected $_originalBaseUrl;
  protected $_defaultLanguage;
  protected $_languages;
  protected $_ignoreUntranslatedPages = false;

  protected $_skipCopyingMedia = false;

  protected $_customRoutes = [];

  protected $_indexFileName = 'index.html';

  public function __construct(App $kirby, array $pathsToCopy = null, Pages $pages = null)
  {
    $this->_kirby = $kirby;

    $this->_pathsToCopy = $pathsToCopy ?: [$kirby->roots()->assets()];
    $this->_pathsToCopy = $this->_resolveRelativePaths($this->_pathsToCopy);
    $this->_outputFolder = $this->_resolveRelativePath('./static');

    $this->_pages = $pages ?: $kirby->site()->index();

    $this->_defaultLanguage = $kirby->languages()->default();
    $this->_languages = $this->_defaultLanguage ? $kirby->languages()->keys() : [$this->_defaultLanguage];
  }

  public function generate(string $outputFolder = './static', string $baseUrl = '/', array $preserve = [])
  {
    $this->_outputFolder = $this->_resolveRelativePath($outputFolder ?: $this->_outputFolder);
    $this->_checkOutputFolder();
    F::write($this->_outputFolder . '/.kirbystatic', '');

    $this->clearFolder($this->_outputFolder, $preserve);
    $this->generatePages($baseUrl);
    foreach ($this->_pathsToCopy as $pathToCopy) {
      $this->copyFiles($pathToCopy);
    }

    return $this->_fileList;
  }

  public function generatePages(string $baseUrl = '/')
  {
    $this->_setOriginalBaseUrl();

    $baseUrl = rtrim($baseUrl, '/') . '/';

    $copyMedia = !$this->_skipCopyingMedia;
    $copyMedia && StaticSiteGeneratorMedia::setActive(true);

    $homePage = $this->_pages->findBy('isHomePage', 'true');
    if ($homePage) {
      $this->_setPageLanguage($homePage, $this->_defaultLanguage ? $this->_defaultLanguage->code() : null);
      $this->_generatePage($homePage, $this->_outputFolder . '/' . $this->_indexFileName, $baseUrl);
    }

    foreach ($this->_languages as $languageCode) {
      $this->_generatePagesByLanguage($baseUrl, $languageCode);
    }

    foreach ($this->_customRoutes as $route) {
      $this->_generateCustomRoute($baseUrl, $route);
    }

    if ($copyMedia) {
      $this->_copyMediaFiles();

      StaticSiteGeneratorMedia::setActive(false);
      StaticSiteGeneratorMedia::clearList();
    }

    $this->_restoreOriginalBaseUrl();
    return $this->_fileList;
  }

  public function skipMedia($skipCopyingMedia = true)
  {
    $this->_skipCopyingMedia = $skipCopyingMedia;
  }

  public function setCustomRoutes(array $customRoutes)
  {
    $this->_customRoutes = $customRoutes;
  }

  public function setIgnoreUntranslatedPages(bool $ignoreUntranslatedPages)
  {
    $this->_ignoreUntranslatedPages = $ignoreUntranslatedPages;
  }

  public function setIndexFileName(string $indexFileName)
  {
    $indexFileName = preg_replace('/[^a-z0-9.]/i', '', $indexFileName);
    if (!preg_replace('/[.]/', '', $indexFileName)) {
      return;
    }

    $this->_indexFileName = $indexFileName;
  }

  protected function _setOriginalBaseUrl()
  {
    if (!$this->_kirby->urls()->base()) {
      $this->_modifyBaseUrl('https://d4l-ssg-base-url');
    }

    $this->_originalBaseUrl = $this->_kirby->urls()->base();
  }

  protected function _restoreOriginalBaseUrl()
  {
    if ($this->_originalBaseUrl === 'https://d4l-ssg-base-url') {
      $this->_modifyBaseUrl('');
    }
  }

  protected function _modifyBaseUrl(string $baseUrl)
  {
    $urls = array_map(function ($url) use ($baseUrl) {
      $newUrl = $url === '/' ? $baseUrl : $baseUrl . $url;
      return strpos($url, 'http') === 0 ? $url : $newUrl;
    }, $this->_kirby->urls()->toArray());
    $this->_kirby = $this->_kirby->clone(['urls' => $urls]);
  }

  protected function _generatePagesByLanguage(string $baseUrl, string $languageCode = null)
  {
    foreach ($this->_pages->keys() as $key) {
      $page = $this->_pages->$key;
      if ($this->_ignoreUntranslatedPages && !$page->translation($languageCode)->exists()) {
        continue;
      }

      $this->_setPageLanguage($page, $languageCode);
      $path = str_replace($this->_originalBaseUrl, '/', $page->url());
      $path = $this->_cleanPath($this->_outputFolder . $path . '/' . $this->_indexFileName);
      try {
        $this->_generatePage($page, $path, $baseUrl);
      } catch (ErrorException $error) {
        $this->_handleRenderError($error, $key, $languageCode);
      }
    }
  }

  protected function _getRouteContent(string $routePath)
  {
    if (!$routePath) {
      return null;
    }

    $routeResult = kirby()
      ->router()
      ->call($routePath, 'GET');

    if ($routeResult instanceof Page) {
      return $routeResult;
    }

    if ($routeResult instanceof \Kirby\Http\Response) {
      $routeResult = $routeResult->body();
    }

    return is_string($routeResult) ? $routeResult : null;
  }

  protected function _generateCustomRoute(string $baseUrl, array $route)
  {
    $path = A::get($route, 'path');
    $page = A::get($route, 'page');
    $routePath = A::get($route, 'route');
    $baseUrl = A::get($route, 'baseUrl', $baseUrl);
    $data = A::get($route, 'data', []);
    $languageCode = A::get($route, 'languageCode');

    if (is_string($page)) {
      $page = page($page);
    }

    $routeContent = $page ? null : $this->_getRouteContent($routePath ?: $path);
    if ($routeContent instanceof Page) {
      $page = $routeContent;
      $routeContent = null;
    }

    if (!$path || (!$page && !$routeContent)) {
      return;
    }

    if (!$page) {
      $page = new Page(['slug' => 'static-site-generator/' . uniqid()]);
    }

    $path = $this->_cleanPath($this->_outputFolder . '/' . $path . '/' . $this->_indexFileName);
    $this->_setPageLanguage($page, $languageCode, false);
    $this->_generatePage($page, $path, $baseUrl, $data, $routeContent);
  }

  protected function _resetPage(Page|Site $page) {
    $page->content = null;

    foreach ($page->children() as $child) {
      $this->_resetPage($child);
    }

    foreach ($page->files() as $file) {
      $file->content = null;
    }
  }

  protected function _setPageLanguage(Page $page, string $languageCode = null, $forceReset = true)
  {
    $this->_resetCollections();

    $kirby = $this->_kirby;
    $kirby->setCurrentTranslation($languageCode);
    $kirby->setCurrentLanguage($languageCode);

    $site = $kirby->site();
    $this->_resetPage($site);

    if ($page->exists() || $forceReset) {
      $this->_resetPage($page);
    }

    $kirby->cache('pages')->flush();
    $site->visit($page, $languageCode);
  }

  protected function _resetCollections()
  {
    (function () {
      $this->collections = null;
    })->bindTo($this->_kirby, 'Kirby\\Cms\\App')($this->_kirby);
  }

  protected function _generatePage(Page $page, string $path, string $baseUrl, array $data = [], string $content = null)
  {
    $page->setSite(null);
    $content = $content ?: $page->render($data);

    $jsonOriginalBaseUrl = trim(json_encode($this->_originalBaseUrl), '"');
    $jsonBaseUrl = trim(json_encode($baseUrl), '"');
    $content = str_replace($this->_originalBaseUrl . '/', $baseUrl, $content);
    $content = str_replace($this->_originalBaseUrl, $baseUrl, $content);
    $content = str_replace($jsonOriginalBaseUrl . '\\/', $jsonBaseUrl, $content);
    $content = str_replace($jsonOriginalBaseUrl, $jsonBaseUrl, $content);

    F::write($path, $content);

    $this->_fileList = array_unique(array_merge($this->_fileList, [$path]));
  }

  public function copyFiles(string $folder = null)
  {
    $outputFolder = $this->_outputFolder;

    if (!$folder || !file_exists($folder)) {
      return $this->_fileList;
    }

    $folderName = $this->_getFolderName($folder);
    $targetPath = $outputFolder . '/' . $folderName;

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

  protected function _copyMediaFiles()
  {
    $outputFolder = $this->_outputFolder;
    $mediaList = StaticSiteGeneratorMedia::getList();

    foreach ($mediaList as $item) {
      $file = $item['root'];
      $path = str_replace($this->_originalBaseUrl, '/', $item['url']);
      $path = $this->_cleanPath($path);
      $path = $outputFolder . $path;
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
    $items = $this->_getFileList($folder);
    return array_reduce(
      $items,
      function ($totalResult, $item) use ($preserve) {
        $folderName = $this->_getFolderName($item);
        if (in_array($folderName, $preserve)) {
          return $totalResult;
        }

        if (strpos($folderName, '.') === 0) {
          return $totalResult;
        }

        $result = is_dir($item) === false ? F::remove($item) : Dir::remove($item);
        return $totalResult && $result;
      },
      true
    );
  }

  protected function _getFolderName(string $folder)
  {
    $segments = explode(DIRECTORY_SEPARATOR, $folder);
    return array_pop($segments);
  }

  protected function _getFileList(string $path, bool $recursively = false)
  {
    $items = array_map(function ($item) {
      return str_replace('/', DIRECTORY_SEPARATOR, $item);
    }, Dir::read($path, [], true));
    if (!$recursively) {
      return $items;
    }

    return array_reduce(
      $items,
      function ($list, $item) {
        if (is_dir($item)) {
          return array_merge($list, $this->_getFileList($item, true));
        }

        return array_merge($list, [$item]);
      },
      []
    ) ?:
      [];
  }

  protected function _resolveRelativePaths(array $paths)
  {
    return array_values(
      array_filter(
        array_map(function ($path) {
          return $this->_resolveRelativePath($path);
        }, $paths)
      )
    );
  }

  protected function _resolveRelativePath(string $path = null)
  {
    if (!$path || strpos($path, '.') !== 0) {
      return realpath($path) ?: $path;
    }

    $path = $this->_kirby->roots()->index() . '/' . $path;
    return realpath($path) ?: $path;
  }

  protected function _cleanPath(string $path): string
  {
    $path = str_replace('//', '/', $path);
    $path = preg_replace('/([^\/]+\.[a-z]{2,5})\/' . $this->_indexFileName . '$/i', '$1', $path);
    $path = preg_replace('/(\.[^\/.]+)\/' . $this->_indexFileName . '$/i', '$1', $path);

    if (strpos($path, '//') !== false) {
      return $this->_cleanPath($path);
    }

    return $path;
  }

  protected function _checkOutputFolder()
  {
    $folder = $this->_outputFolder;
    if (!$folder) {
      throw new Error('Error: Please specify a valid output folder!');
    }

    if (Dir::isEmpty($folder)) {
      return;
    }

    if (!Dir::isWritable($folder)) {
      throw new Error('Error: The output folder is not writable');
    }

    $fileList = array_map(function ($path) use ($folder) {
      return str_replace($folder . DIRECTORY_SEPARATOR, '', $path);
    }, $this->_getFileList($folder));

    if (in_array($this->_indexFileName, $fileList) || in_array('.kirbystatic', $fileList)) {
      return;
    }

    throw new Error(
      'Hello! It seems the given output folder "' .
        $folder .
        '" already contains other files or folders. ' .
        'Please specify a path that does not exist yet, or is empty. If it absolutely has to be this path, create ' .
        'an empty .kirbystatic file and retry. WARNING: Any contents of the output folder not starting with "." ' .
        'are erased before generation! Information on preserving individual files and folders can be found in the Readme.'
    );
  }

  protected function _handleRenderError(ErrorException $error, string $key, string $languageCode = null)
  {
    $message = $error->getMessage();
    $file = str_replace($this->_kirby->roots()->index(), '', $error->getFile());
    $line = $error->getLine();
    throw new Error(
      "Error in $file line $line while rendering page \"$key\"" .
        ($languageCode ? " ($languageCode)" : '') .
        ": $message"
    );
  }
}
