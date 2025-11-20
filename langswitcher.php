<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Cache;
use Grav\Common\File\CompiledMarkdownFile;
use Grav\Common\Language\Language;
use Grav\Common\Language\LanguageCodes;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use Twig\TwigFunction;

class LangSwitcherPlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100001],
                ['onPluginsInitialized', 0]
            ]
        ];
    }

    /**
     * [onPluginsInitialized:100000] Composer autoload.
     *
     * @return ClassLoader
     */
    public function autoload()
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize configuration
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            $this->active = false;
            return;
        }

        $this->enable([
            'onTwigInitialized'   => ['onTwigInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
        ]);
    }

    /** Add the native_name function */
    public function onTwigInitialized()
    {
        $this->grav['twig']->twig()->addFunction(
            new TwigFunction('native_name', function($key) {
                return LanguageCodes::getNativeName($key);
            })
        );
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Generate localized route based on the translated slugs found through the pages hierarchy
     */
    protected function getTranslatedUrl($lang, $path)
    {
        if (empty($path)) {
            return null;
        }

        $cache_key = 'langswitcher_url_' . $lang . '_' . md5($path);
        $cache = $this->grav['cache'];
        $cached = $cache->fetch($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $url = $this->resolveTranslatedRoute($lang, $path);

        $cache->save($cache_key, $url);

        return $url;
    }

    protected function resolveTranslatedRoute($lang, $path)
    {
        $pages_dir = $this->grav['locator']->findResource('page://');

        if (strpos($path, $pages_dir) === 0) {
            $rel_path = substr($path, strlen($pages_dir));
        } else {
            return null;
        }

        $parts = explode('/', ltrim($rel_path, '/'));
        $current_path = $pages_dir;
        $slugs = [];

        foreach ($parts as $part) {
            if (empty($part)) continue;
            $current_path .= '/' . $part;

            $match = null;
            $files = glob($current_path . '/*.md');

            if ($files) {
                foreach ($files as $file) {
                    $name = basename($file);
                    if (Utils::endsWith($name, ".$lang.md")) {
                        $match = $file;
                        break;
                    }
                }

                if (!$match) {
                    foreach ($files as $file) {
                        $name = basename($file);
                        if (!preg_match('/\\.[a-z]{2}\\.md$/', $name)) {
                            $match = $file;
                            break;
                        }
                        $default = $this->grav['language']->getDefault();
                        if (Utils::endsWith($name, ".$default.md")) {
                            $match = $file;
                            break;
                        }
                    }
                }
            }

            if ($match) {
                $file = CompiledMarkdownFile::instance($match);
                $header = $file->header();
                $folder_slug = preg_replace('/^[0-9]+\./u', '', $part);
                $slug = $header['slug'] ?? $folder_slug;
                $slugs[] = $slug;
            } else {
                return null;
            }
        }

        $route = implode('/', $slugs);
        $home_alias = $this->config->get('system.home.alias');
        if ($route == trim($home_alias, '/')) {
            $route = '';
        }

        if ($this->config->get('system.force_lowercase_urls')) {
            $route = mb_strtolower($route);
        }

        $uri = $this->grav['uri'];
        $language = $this->grav['language'];

        $base = $uri->rootUrl($this->config->get('system.absolute_urls'));

        $include_default = $this->config->get('system.languages.include_default_lang');
        $default = $language->getDefault();

        $lang_prefix = '';
        if ($include_default || $lang !== $default) {
            $lang_prefix = '/' . $lang;
        }

        $url = $base . $lang_prefix . ($route ? '/' . $route : '');

        $ext = '';
        if ($this->config->get('system.pages.append_url_extension')) {
            $ext = '.' . $this->config->get('system.pages.extension', 'html');
        }
        $url .= $ext;

        return $url;
    }

    /**
     * Set needed variables to display Langswitcher.
     */
    public function onTwigSiteVariables()
    {

        /** @var PageInterface $page */
        $page = $this->grav['page'];

        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        $data = new \stdClass;
        $data->page_route = $page->rawRoute();
        if ($page->home()) {
            $data->page_route = '/';
        }

        $languages = $this->grav['language']->getLanguages();
        $data->languages = $languages;

        if ($this->config->get('plugins.langswitcher.untranslated_pages_behavior') !== 'none') {
            $translated_pages = [];
            foreach ($languages as $language) {
                $translated_pages[$language] = null;
                $page_name_without_ext = substr($page->name(), 0, -(strlen($page->extension())));
                $translated_page_path = $page->path() . DS . $page_name_without_ext . '.' . $language . '.md';
                if (!file_exists($translated_page_path) and $language == $this->grav['language']->getDefault()) {
                    $translated_page_path = $page->path() . DS . $page_name_without_ext . '.md';
                }
                if (file_exists($translated_page_path)) {
                    $translated_page = new Page();
                    $translated_page->init(new \SplFileInfo($translated_page_path), $language . '.md');
                    $translated_pages[$language] = $translated_page;
                }
            }
            $data->translated_pages = $translated_pages;
        }

        $language = $this->grav['language'];
        $active = $language->getActive() ?? $language->getDefault();

        if ($this->config->get('plugins.langswitcher.translated_urls', true)) {
            $data->translated_routes = [$active => $page->url()];

            foreach ($data->languages as $lang) {
                if ($lang === $active) {
                    continue;
                }

                $translated = $this->getTranslatedUrl($lang, $page->path());
                $data->translated_routes[$lang] = $translated ?? $data->page_route;
            }
        }

        $data->current = $language->getLanguage();

        $this->grav['twig']->twig_vars['langswitcher'] = $this->grav['langswitcher'] = $data;

        if ($this->config->get('plugins.langswitcher.built_in_css')) {
            $this->grav['assets']->add('plugin://langswitcher/css/langswitcher.css');
        }
    }

    public function getNativeName($code) {

    }
}