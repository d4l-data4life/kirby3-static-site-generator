<?php

namespace D4L;

use Kirby\Cms\App as Kirby;

require_once __DIR__ . '/media.class.php';
require_once __DIR__ . '/class.php';


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
            $skipMedia = $kirby->option('d4l.static_site_generator.skip_media', false);
            $skipTemplates = array_diff($kirby->option('d4l.static_site_generator.skip_templates', []), ['home']);
            $customRoutes = $kirby->option('d4l.static_site_generator.custom_routes', []);
            $customFilters = $kirby->option('d4l.static_site_generator.custom_filters', []);
            $ignoreUntranslatedPages = $kirby->option('d4l.static_site_generator.ignore_untranslated_pages', false);
            $indexFileName = $kirby->option('d4l.static_site_generator.index_file_name', 'index.html');
            if (!empty($skipTemplates)) {
              array_push($customFilters, ['intendedTemplate', 'not in', $skipTemplates]);
            }

            $pages = $kirby->site()->index();
            foreach ($customFilters as $filter) {
              $pages = $pages->filterBy(...$filter);
            }

            $staticSiteGenerator = new StaticSiteGenerator($kirby, null, $pages);
            $staticSiteGenerator->skipMedia($skipMedia);
            $staticSiteGenerator->setCustomRoutes($customRoutes);
            $staticSiteGenerator->setIgnoreUntranslatedPages($ignoreUntranslatedPages);
            $staticSiteGenerator->setIndexFileName($indexFileName);
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
