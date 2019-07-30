<?php

namespace D4L;

use Kirby;

require_once __DIR__ . DS . 'media.class.php';
require_once __DIR__ . DS . 'class.php';


Kirby::plugin('d4l/static-site-generator', [
  'api' => [
    'routes' => function ($kirby) {
      $endpoint = $kirby->option('d4l.static_site_generator.endpoint');
      if (!$endpoint) {
        return [];
      }

      return [
        [
          'pattern' => $endpoint,
          'action' => function () use ($kirby) {
            $outputFolder = $kirby->option('d4l.static_site_generator.output_folder', './static');
            $baseUrl = $kirby->option('d4l.static_site_generator.base_url', '/');
            $preserve = $kirby->option('d4l.static_site_generator.preserve', []);

            $staticSiteGenerator = new StaticSiteGenerator($kirby);
            $list = $staticSiteGenerator->generate($outputFolder, $baseUrl, $preserve);
            $count = count($list);
            return ['success' => true, 'files' => $list, 'message' => "$count files generated / copied"];
          },
          'method' => 'POST'
        ]
      ];
    }
  ],
  'fields' => [
    'staticSiteGenerator' => [
      'props' => [
        'endpoint' => function () {
          return $this->kirby()->option('d4l.static_site_generator.endpoint');
        }
      ]
    ]
  ]
]);
