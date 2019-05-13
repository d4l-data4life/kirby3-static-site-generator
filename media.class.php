<?php

namespace D4L;

use Kirby;

$versionFn = Kirby::component('file::version');
$urlFn = Kirby::component('file::url');


Kirby::plugin('d4l/static-site-generator-media', [
  'components' => [
    'file::version' => function (Kirby $kirby, $file, array $options = []) use ($versionFn) {
      $version = $versionFn($kirby, $file, $options);

      if (!StaticSiteGeneratorMedia::isActive()) {
        return $version;
      }

      $version->save();
      StaticSiteGeneratorMedia::register($version->root(), $version->url());
      return $version;
    },
    'file::url' => function (Kirby $kirby, $file, array $options = []) use ($urlFn) {
      $url = $urlFn($kirby, $file, $options);

      if (!StaticSiteGeneratorMedia::isActive()) {
        return $url;
      }

      // $file->publish();
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
