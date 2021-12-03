<?php

namespace D4L;

use Kirby\Cms\App as Kirby;

$versionFn = kirby()->component('file::version');
$urlFn = kirby()->component('file::url');


Kirby::plugin('d4l/static-site-generator-media', [
  'components' => [
    'file::version' => function (Kirby $kirby, $file, array $options = []) use ($versionFn) {
      $version = $versionFn($kirby, $file, $options);
      if ($mediaPlugin = $kirby->option('d4l.static_site_generator.media_plugin', null)) {
        $version = Kirby::plugin($mediaPlugin)->extends()['components']['file::version']($kirby, $file, $options);
      }

      if (!StaticSiteGeneratorMedia::isActive()) {
        return $version;
      }

      if (!$version->exists()) {
        $version->save();
      }

      $url = $version->url();
      if ($urlTransform = $kirby->option('d4l.static_site_generator.media_url_transform', null)) {
        $url = $urlTransform($url, $kirby);
      }

      StaticSiteGeneratorMedia::register($version->root(), $url);
      return $version;
    },
    'file::url' => function (Kirby $kirby, $file, array $options = []) use ($urlFn) {
      $url = $urlFn($kirby, $file, $options);
      $mediaPlugin = $kirby->option('d4l.static_site_generator.media_plugin', null);
      if ($mediaPlugin) {
        $url = Kirby::plugin($mediaPlugin)->extends()['components']['file::url']($kirby, $file, $options);
      }

      if (!StaticSiteGeneratorMedia::isActive()) {
        return $url;
      }

      if ($urlTransform = $kirby->option('d4l.static_site_generator.media_url_transform', null)) {
        $url = $urlTransform($url, $kirby);
      }

      StaticSiteGeneratorMedia::register($file->root(), $url);
      return $url;
    }
  ]
]);


class StaticSiteGeneratorMedia
{
  protected static $_instance;
  protected $_active = false;
  protected $_list = [];

  public static function getInstance()
  {
    if (!static::$_instance) {
      static::$_instance = new static();
    }

    return static::$_instance;
  }

  public static function register($root, $url)
  {
    $instance = static::getInstance();
    $item = [
      'root' => $root,
      'url' => $url
    ];

    if (in_array($item, $instance->_list)) {
      return;
    }

    $instance->_list[] = $item;
  }

  public static function getList()
  {
    $instance = static::getInstance();
    return $instance->_list;
  }

  public static function clearList()
  {
    $instance = static::getInstance();
    $instance->_list = [];
  }

  public static function isActive()
  {
    $instance = static::getInstance();
    return $instance->_active;
  }

  public static function setActive(bool $active)
  {
    $instance = static::getInstance();
    $instance->_active = $active;
  }
}
